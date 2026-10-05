<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Messaging\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Liberu\SocialNetwork\Messaging\Actions\AddParticipant;
use Liberu\SocialNetwork\Messaging\Actions\AddReaction;
use Liberu\SocialNetwork\Messaging\Actions\CreateConversation;
use Liberu\SocialNetwork\Messaging\Actions\DeleteMessage;
use Liberu\SocialNetwork\Messaging\Actions\GetConversation;
use Liberu\SocialNetwork\Messaging\Actions\MarkConversationRead;
use Liberu\SocialNetwork\Messaging\Actions\RemoveParticipant;
use Liberu\SocialNetwork\Messaging\Actions\RemoveReaction;
use Liberu\SocialNetwork\Messaging\Actions\SendMessage;
use Liberu\SocialNetwork\Messaging\Actions\UnreadMessageCount;
use Liberu\SocialNetwork\Messaging\Models\Conversation;
use Liberu\SocialNetwork\Messaging\Models\Message;
use Liberu\SocialNetwork\Messaging\Models\MessageReaction;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Actions\ResolveDisplayNames;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Inbox for the member panel: a table of my conversations and a chat that opens
 * in a slide-over. Every write goes through the actions of social-network-messaging.
 */
final class Conversations extends Page implements HasTable
{
    use InteractsWithTable;

    /** Reactions offered under each message. */
    public const array REACTIONS = ['👍', '❤️', '😂', '🎉', '😮'];

    /** How many of the latest messages the chat shows. */
    private const int THREAD_LENGTH = 100;

    protected string $view = 'social-network-messaging-filament::pages.conversations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    /** Conversation shown in the chat slide-over. */
    public ?string $openConversationId = null;

    public string $reply = '';

    public bool $replyEncrypted = false;

    private ?Profile $viewer = null;

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->check()) {
            return null;
        }

        $unread = app(UnreadMessageCount::class)->handle(app(GetProfile::class)->forUser(auth()->id()));

        return $unread > 0 ? (string) $unread : null;
    }

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->myConversations())
            ->columns([
                TextColumn::make('title')
                    ->label('Conversation')
                    ->searchable()
                    ->weight('bold')
                    ->state(fn (Conversation $record): string => $this->conversationTitle($record))
                    ->description(fn (Conversation $record): ?string => $this->lastMessagePreview($record)),
                TextColumn::make('people')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Conversation $record): array => array_values($this->memberNames($record, exceptMe: true)))
                    ->limitList(3)
                    ->placeholder('Only you'),
                TextColumn::make('unread_count')
                    ->label('Unread')
                    ->badge()
                    ->color('primary')
                    ->state(fn (Conversation $record): ?int => (int) $record->unread_count > 0 ? (int) $record->unread_count : null),
                TextColumn::make('last_activity_at')
                    ->label('Last activity')
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->filters([
                Filter::make('unread')
                    ->label('Unread only')
                    ->query(fn (Builder $query): Builder => $query->whereExists(
                        $this->unreadMessages(DB::table('social_messages')->selectRaw('1')),
                    )),
            ])
            ->recordAction('open')
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-chat-bubble-oval-left-ellipsis')
                    ->button()
                    ->slideOver()
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalHeading(fn (Conversation $record): string => $this->conversationTitle($record))
                    ->modalDescription(fn (Conversation $record): string => implode(', ', $this->memberNames($record)))
                    ->mountUsing(fn (Conversation $record) => $this->openConversation($record))
                    ->modalContent(fn (): View => view('social-network-messaging-filament::partials.thread', [
                        'messages' => $this->threadMessages(),
                        'reactions' => self::REACTIONS,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                ActionGroup::make([
                    Action::make('addPeople')
                        ->label('Add people')
                        ->icon('heroicon-o-user-plus')
                        ->schema([
                            Select::make('profiles')
                                ->label('People')
                                ->multiple()
                                ->required()
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search, Conversation $record): array => app(ResolveDisplayNames::class)
                                    ->search($search, array_keys($this->memberNames($record))))
                                ->getOptionLabelsUsing(fn (array $values): array => app(ResolveDisplayNames::class)->handle($values)),
                        ])
                        ->action(fn (Conversation $record, array $data) => $this->attempt(function () use ($record, $data): void {
                            foreach (Profile::query()->whereKey($data['profiles'])->get() as $participant) {
                                app(AddParticipant::class)->handle($this->viewer(), (string) $record->getKey(), $participant);
                            }
                        }, 'People added')),
                    Action::make('removePerson')
                        ->label('Remove someone')
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->visible(fn (Conversation $record): bool => $this->isCreator($record) && count($this->memberNames($record, exceptMe: true)) > 0)
                        ->schema([
                            Select::make('profile')
                                ->label('Person')
                                ->required()
                                ->options(fn (Conversation $record): array => $this->memberNames($record, exceptMe: true)),
                        ])
                        ->action(fn (Conversation $record, array $data) => $this->attempt(
                            fn () => app(RemoveParticipant::class)->handle($this->viewer(), (string) $record->getKey(), Profile::query()->findOrFail($data['profile'])),
                            'Person removed',
                        )),
                    Action::make('leave')
                        ->label('Leave conversation')
                        ->icon('heroicon-o-arrow-left-start-on-rectangle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('You will stop receiving its messages. Someone in it can add you back.')
                        ->action(fn (Conversation $record) => $this->attempt(
                            fn () => app(RemoveParticipant::class)->handle($this->viewer(), (string) $record->getKey(), $this->viewer()),
                            'You left the conversation',
                        )),
                ])
                    ->label('More')
                    ->icon('heroicon-o-ellipsis-vertical'),
            ])
            ->emptyStateHeading('No conversations yet')
            ->emptyStateDescription('Start one with the “New conversation” button.')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(Conversation::class)
                ->label('New conversation')
                ->icon('heroicon-o-plus')
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('participants')
                        ->label('With')
                        ->multiple()
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => app(ResolveDisplayNames::class)
                            ->search($search, [(string) $this->viewer()->getKey()]))
                        ->getOptionLabelsUsing(fn (array $values): array => app(ResolveDisplayNames::class)->handle($values)),
                    TextInput::make('title')
                        ->maxLength(200)
                        ->helperText('Optional. Without a title, the conversation is named after its people.'),
                    Textarea::make('message')
                        ->label('First message')
                        ->rows(3)
                        ->maxLength((int) config('social-network-messaging.max_body_length', 10000)),
                ]))
                ->using(fn (array $data): Conversation => $this->attempt(function () use ($data): Conversation {
                    $conversation = app(CreateConversation::class)->handle(
                        $this->viewer(),
                        filled($data['title'] ?? null) ? $data['title'] : null,
                        array_map('strval', $data['participants']),
                    );

                    if (filled($data['message'] ?? null)) {
                        app(SendMessage::class)->handle($this->viewer(), (string) $conversation->getKey(), $data['message']);
                    }

                    return $conversation;
                })),
        ];
    }

    public function sendReply(): void
    {
        $this->validate(['reply' => ['required', 'string', 'max:'.(int) config('social-network-messaging.max_body_length', 10000)]]);

        $sent = $this->attemptInChat(function (): bool {
            app(SendMessage::class)->handle($this->viewer(), $this->openConversationIdOrFail(), $this->reply, encrypted: $this->replyEncrypted);
            app(MarkConversationRead::class)->handle($this->viewer(), $this->openConversationIdOrFail());

            return true;
        });

        if ($sent === true) {
            $this->reset('reply');
        }
    }

    public function toggleReaction(string $messageId, string $emoji): void
    {
        abort_unless(in_array($emoji, self::REACTIONS, true), 422);
        $message = $this->messageInOpenConversation($messageId);

        $alreadyReacted = MessageReaction::query()
            ->where(['message_id' => $message->getKey(), 'profile_id' => $this->viewer()->getKey(), 'emoji' => $emoji])
            ->exists();

        $this->attemptInChat(fn () => $alreadyReacted
            ? app(RemoveReaction::class)->handle($this->viewer(), $message, $emoji)
            : app(AddReaction::class)->handle($this->viewer(), $message, $emoji));
    }

    public function deleteMessage(string $messageId): void
    {
        $message = $this->messageInOpenConversation($messageId);

        $this->attemptInChat(
            fn () => app(DeleteMessage::class)->handle($this->viewer(), (string) $message->conversation_id, $message),
            'Message deleted',
        );
    }

    /**
     * attempt() for the chat's own buttons: they are not Filament actions, so a
     * refusal must end the call here instead of bubbling up as Halt.
     */
    private function attemptInChat(Closure $callback, ?string $success = null): mixed
    {
        try {
            return $this->attempt($callback, $success);
        } catch (Halt) {
            return null;
        }
    }

    private function openConversation(Conversation $conversation): void
    {
        $this->openConversationId = (string) $conversation->getKey();
        $this->reset('reply', 'replyEncrypted');
        app(MarkConversationRead::class)->handle($this->viewer(), $this->openConversationId);
    }

    private function openConversationIdOrFail(): string
    {
        abort_if($this->openConversationId === null, 404);

        return $this->openConversationId;
    }

    private function messageInOpenConversation(string $messageId): Message
    {
        return Message::query()
            ->where('conversation_id', $this->openConversationIdOrFail())
            ->findOrFail($messageId);
    }

    /**
     * The open conversation's latest messages, oldest first, ready for the view.
     *
     * @return list<array{id: string, mine: bool, sender: string, body: ?string, encrypted: bool, sent_at: mixed, reactions: list<array{emoji: string, count: int, mine: bool}>}>
     */
    private function threadMessages(): array
    {
        if ($this->openConversationId === null) {
            return [];
        }

        $conversation = app(GetConversation::class)->handle($this->viewer(), $this->openConversationId);
        $messages = $conversation->messages->take(self::THREAD_LENGTH)->reverse()->values();
        $me = (string) $this->viewer()->getKey();
        $names = app(ResolveDisplayNames::class)->handle($messages->pluck('sender_profile_id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all());

        $reactions = MessageReaction::query()
            ->whereIn('message_id', $messages->modelKeys())
            ->get()
            ->groupBy('message_id');

        return $messages->map(fn (Message $message): array => [
            'id' => (string) $message->getKey(),
            'mine' => (string) $message->sender_profile_id === $me,
            'sender' => $names[(string) $message->sender_profile_id] ?? '—',
            'body' => $message->displayBody(),
            'encrypted' => $message->encrypted_body !== null,
            'sent_at' => $message->created_at,
            'reactions' => collect($reactions->get($message->getKey(), []))
                ->groupBy('emoji')
                ->map(fn ($group, string $emoji): array => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'mine' => $group->contains(fn (MessageReaction $reaction): bool => (string) $reaction->profile_id === $me),
                ])
                ->values()
                ->all(),
        ])->all();
    }

    /**
     * Same rule as ListConversations (conversations I belong to), with the unread
     * count and last activity added, most recent first.
     */
    private function myConversations(): Builder
    {
        $me = (string) $this->viewer()->getKey();

        return Conversation::query()
            ->select('social_conversations.*')
            ->addSelect([
                'unread_count' => $this->unreadMessages(DB::table('social_messages')->selectRaw('count(*)')),
                'last_message_at' => DB::table('social_messages')
                    ->selectRaw('max(created_at)')
                    ->whereColumn('social_messages.conversation_id', 'social_conversations.id')
                    ->whereNull('social_messages.deleted_at'),
            ])
            ->selectRaw('coalesce((select max(m.created_at) from social_messages m where m.conversation_id = social_conversations.id and m.deleted_at is null), social_conversations.created_at) as last_activity_at')
            ->whereIn('social_conversations.id', DB::table('social_conversation_members')->select('conversation_id')->where('profile_id', $me))
            ->orderByDesc('last_activity_at');
    }

    /**
     * Restricts a social_messages query to the current row's messages I have not
     * read: sent by someone else after my read_at (UnreadMessageCount's rule).
     */
    private function unreadMessages(QueryBuilder $messages): QueryBuilder
    {
        $me = (string) $this->viewer()->getKey();

        return $messages
            ->whereColumn('social_messages.conversation_id', 'social_conversations.id')
            ->where('social_messages.sender_profile_id', '!=', $me)
            ->whereNull('social_messages.deleted_at')
            ->whereNotExists(fn (QueryBuilder $read) => $read
                ->selectRaw('1')
                ->from('social_conversation_members as reader')
                ->whereColumn('reader.conversation_id', 'social_messages.conversation_id')
                ->where('reader.profile_id', $me)
                ->whereColumn('reader.read_at', '>=', 'social_messages.created_at'));
    }

    private function conversationTitle(Conversation $conversation): string
    {
        if (filled($conversation->title)) {
            return (string) $conversation->title;
        }

        $others = $this->memberNames($conversation, exceptMe: true);

        return $others === [] ? 'Just you' : implode(', ', $others);
    }

    private function lastMessagePreview(Conversation $conversation): ?string
    {
        $message = Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->latest()
            ->first();

        if ($message === null) {
            return null;
        }

        $sender = (string) $message->sender_profile_id === (string) $this->viewer()->getKey()
            ? 'You'
            : (app(ResolveDisplayNames::class)->handle([(string) $message->sender_profile_id])[(string) $message->sender_profile_id] ?? '—');

        return $sender.': '.str((string) $message->displayBody())->squish()->limit(60);
    }

    private function isCreator(Conversation $conversation): bool
    {
        return (string) $conversation->created_by_profile_id === (string) $this->viewer()->getKey();
    }

    /** @return array<string, string> profile id => display name */
    private function memberNames(Conversation $conversation, bool $exceptMe = false): array
    {
        $ids = DB::table('social_conversation_members')
            ->where('conversation_id', $conversation->getKey())
            ->when($exceptMe, fn (QueryBuilder $query) => $query->where('profile_id', '!=', $this->viewer()->getKey()))
            ->orderBy('id')
            ->pluck('profile_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return app(ResolveDisplayNames::class)->handle($ids);
    }

    /**
     * Runs a module action and turns its refusals (invalid data, a denied gate
     * or an abort) into a notification instead of an error page.
     */
    private function attempt(Closure $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback();
        } catch (InvalidArgumentException|AuthorizationException|HttpException $exception) {
            Notification::make()
                ->title($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'You are not allowed to do that.')
                ->danger()
                ->send();

            throw new Halt();
        }

        if ($success !== null) {
            Notification::make()->title($success)->success()->send();
        }

        return $result;
    }
}
