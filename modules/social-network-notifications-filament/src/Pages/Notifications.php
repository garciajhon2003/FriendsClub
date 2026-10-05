<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Notifications\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Liberu\SocialNetwork\Notifications\Actions\DismissNotification;
use Liberu\SocialNetwork\Notifications\Actions\MarkAllRead;
use Liberu\SocialNetwork\Notifications\Actions\MarkRead;
use Liberu\SocialNetwork\Notifications\Actions\UnreadCount;
use Liberu\SocialNetwork\Notifications\Actions\UpdatePreferences;
use Liberu\SocialNetwork\Notifications\Models\NotificationPreference;
use Liberu\SocialNetwork\Notifications\Models\SocialNotification;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Notification inbox for the member panel. Every write goes through the
 * actions of social-network-notifications.
 */
final class Notifications extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Icon for each known notification kind; the first segment is a fallback,
     * so "social.event.reminder" uses "social.event" when it is not listed.
     */
    private const array KIND_ICONS = [
        'social.follow' => 'heroicon-o-user-plus',
        'social.reaction' => 'heroicon-o-heart',
        'social.comment' => 'heroicon-o-chat-bubble-bottom-center-text',
        'social.message' => 'heroicon-o-envelope',
        'social.event' => 'heroicon-o-calendar-days',
        'social.community' => 'heroicon-o-user-group',
        'social.mention' => 'heroicon-o-at-symbol',
    ];

    protected string $view = 'social-network-notifications-filament::pages.notifications';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    private ?Profile $viewer = null;

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->check()) {
            return null;
        }

        $unread = app(UnreadCount::class)->handle(app(GetProfile::class)->forUser(auth()->id()));

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
            ->query(fn (): Builder => SocialNotification::query()->where('profile_id', $this->viewer()->getKey()))
            ->columns([
                TextColumn::make('message')
                    ->label('Notification')
                    ->state(fn (SocialNotification $record): string => $this->messageOf($record))
                    ->icon(fn (SocialNotification $record): string => $this->iconOf($record->kind))
                    ->iconColor(fn (SocialNotification $record): string => $record->state === 'unread' ? 'primary' : 'gray')
                    ->weight(fn (SocialNotification $record): string => $record->state === 'unread' ? 'bold' : 'normal')
                    ->wrap(),
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => $this->kindLabel($state)),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'unread' => 'primary',
                        'dismissed' => 'gray',
                        default => 'success',
                    }),
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(['unread' => 'Unread', 'read' => 'Read', 'dismissed' => 'Dismissed'])
                    ->multiple()
                    ->default(['unread', 'read']),
                SelectFilter::make('kind')
                    ->label('Type')
                    ->options(fn (): array => SocialNotification::query()
                        ->where('profile_id', $this->viewer()->getKey())
                        ->distinct()
                        ->orderBy('kind')
                        ->pluck('kind')
                        ->mapWithKeys(fn (string $kind): array => [$kind => $this->kindLabel($kind)])
                        ->all()),
            ])
            ->recordActions([
                Action::make('markRead')
                    ->label('Mark read')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (SocialNotification $record): bool => $record->state === 'unread')
                    ->action(fn (SocialNotification $record) => $this->attempt(
                        fn () => app(MarkRead::class)->handle($this->viewer(), (string) $record->getKey()),
                    )),
                Action::make('dismiss')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (SocialNotification $record): bool => $record->state !== 'dismissed')
                    ->action(fn (SocialNotification $record) => $this->attempt(
                        fn () => app(DismissNotification::class)->handle($this->viewer(), (string) $record->getKey()),
                        'Notification dismissed',
                    )),
            ])
            ->toolbarActions([
                BulkAction::make('markSelectedRead')
                    ->label('Mark read')
                    ->icon('heroicon-o-check')
                    ->action(fn (Collection $records) => $this->attempt(function () use ($records): void {
                        foreach ($records->where('state', 'unread') as $record) {
                            app(MarkRead::class)->handle($this->viewer(), (string) $record->getKey());
                        }
                    }, 'Marked as read'))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('dismissSelected')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->action(fn (Collection $records) => $this->attempt(function () use ($records): void {
                        foreach ($records->where('state', '!=', 'dismissed') as $record) {
                            app(DismissNotification::class)->handle($this->viewer(), (string) $record->getKey());
                        }
                    }, 'Notifications dismissed'))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->emptyStateHeading('You are all caught up')
            ->emptyStateDescription('New follows, reactions, comments and reminders will show up here.')
            ->emptyStateIcon('heroicon-o-bell-slash')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllRead')
                ->label('Mark all as read')
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->action(fn () => $this->attempt(function (): void {
                    $count = app(MarkAllRead::class)->handle($this->viewer());

                    Notification::make()
                        ->title($count === 0 ? 'Nothing to mark' : "{$count} marked as read")
                        ->success()
                        ->send();
                })),
            Action::make('preferences')
                ->label('Preferences')
                ->icon('heroicon-o-adjustments-horizontal')
                ->modalDescription('Choose how and when you want to be notified.')
                ->fillForm(fn (): array => $this->preferencesFormState())
                ->schema([
                    CheckboxList::make('channels')
                        ->label('Notify me by')
                        ->options($this->channelOptions())
                        ->columns(3),
                    Section::make('Quiet hours')
                        ->description('No notifications are sent during this time.')
                        ->compact()
                        ->schema([
                            Toggle::make('quiet_enabled')
                                ->label('Use quiet hours')
                                ->live(),
                            Grid::make(2)->schema([
                                TimePicker::make('quiet_from')
                                    ->label('From')
                                    ->seconds(false)
                                    ->required(fn (Get $get): bool => (bool) $get('quiet_enabled')),
                                TimePicker::make('quiet_to')
                                    ->label('To')
                                    ->seconds(false)
                                    ->required(fn (Get $get): bool => (bool) $get('quiet_enabled')),
                            ])->visible(fn (Get $get): bool => (bool) $get('quiet_enabled')),
                        ]),
                    Select::make('digest')
                        ->label('Email summary')
                        ->options(['off' => 'Never', 'daily' => 'Once a day', 'weekly' => 'Once a week'])
                        ->default('off')
                        ->required(),
                ])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(UpdatePreferences::class)->handle($this->viewer(), [
                        'channels' => array_values($data['channels'] ?? []),
                        'quiet_hours' => ($data['quiet_enabled'] ?? false)
                            ? ['from' => substr((string) $data['quiet_from'], 0, 5), 'to' => substr((string) $data['quiet_to'], 0, 5)]
                            : [],
                        'digest' => ['frequency' => $data['digest'] ?? 'off'],
                    ]),
                    'Preferences saved',
                )),
        ];
    }

    /** @return array<string, mixed> */
    private function preferencesFormState(): array
    {
        $preferences = NotificationPreference::query()->where('profile_id', $this->viewer()->getKey())->first();
        $quiet = $preferences?->quiet_hours ?? [];

        return [
            'channels' => $preferences?->channels ?? ['in_app'],
            'quiet_enabled' => isset($quiet['from'], $quiet['to']),
            'quiet_from' => $quiet['from'] ?? null,
            'quiet_to' => $quiet['to'] ?? null,
            'digest' => $preferences?->digest['frequency'] ?? 'off',
        ];
    }

    /** @return array<string, string> */
    private function channelOptions(): array
    {
        $labels = ['in_app' => 'In the app', 'email' => 'Email', 'push' => 'Push'];

        return collect((array) config('social-network-notifications.channels'))
            ->mapWithKeys(fn (string $channel): array => [$channel => $labels[$channel] ?? Str::headline($channel)])
            ->all();
    }

    /** The payload's message when the sender wrote one, otherwise the type. */
    private function messageOf(SocialNotification $notification): string
    {
        $message = $notification->payload['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $this->kindLabel($notification->kind);
    }

    /** "social.event.reminder" → "Event reminder". */
    private function kindLabel(string $kind): string
    {
        return Str::ucfirst(Str::lower(Str::headline(str_replace('.', ' ', Str::after($kind, 'social.')))));
    }

    private function iconOf(string $kind): string
    {
        $segments = explode('.', $kind);

        while ($segments !== []) {
            $icon = self::KIND_ICONS[implode('.', $segments)] ?? null;
            if ($icon !== null) {
                return $icon;
            }
            array_pop($segments);
        }

        return 'heroicon-o-bell';
    }

    /**
     * Runs a module action and turns its refusals into a notification instead
     * of an error page.
     */
    private function attempt(Closure $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback();
        } catch (InvalidArgumentException|AuthorizationException $exception) {
            Notification::make()
                ->title($exception instanceof AuthorizationException ? 'You are not allowed to do that.' : $exception->getMessage())
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
