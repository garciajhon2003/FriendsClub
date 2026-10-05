<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Publishing\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Actions\ResolveDisplayNames;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Actions\CreatePublication;
use Liberu\SocialNetwork\Publishing\Actions\DeletePublication;
use Liberu\SocialNetwork\Publishing\Actions\PublishPublication;
use Liberu\SocialNetwork\Publishing\Actions\UpdatePublication;
use Liberu\SocialNetwork\Publishing\Actions\UpdatePublicationEnrichments;
use Liberu\SocialNetwork\Publishing\Models\Publication;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "My publications" for the member panel: write posts and articles, enrich
 * them with mentions, hashtags, links and a poll, then publish now or schedule.
 * Every write goes through the actions of social-network-publishing.
 */
final class Publications extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array<string, string> */
    private const array AUDIENCES = ['public' => 'Everyone', 'followers' => 'Followers', 'private' => 'Only me'];

    protected string $view = 'social-network-publishing-filament::pages.publications';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'My publications';

    protected static ?string $navigationLabel = 'Publications';

    private ?Profile $viewer = null;

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Publication::query()->where('author_profile_id', $this->viewer()->getKey()))
            ->columns([
                TextColumn::make('title')
                    ->label('Publication')
                    ->searchable(['title', 'body'])
                    ->weight('bold')
                    ->state(fn (Publication $record): string => filled($record->title) ? (string) $record->title : str((string) $record->body)->squish()->limit(60)->toString())
                    ->description(fn (Publication $record): ?string => filled($record->title) && filled($record->body) ? str((string) $record->body)->squish()->limit(90)->toString() : null)
                    ->wrap(),
                TextColumn::make('kind')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('audience')
                    ->badge()
                    ->color('gray')
                    ->icon(fn (string $state): string => match ($state) {
                        'public' => 'heroicon-m-globe-alt',
                        'followers' => 'heroicon-m-users',
                        default => 'heroicon-m-lock-closed',
                    })
                    ->formatStateUsing(fn (string $state): string => self::AUDIENCES[$state] ?? $state),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Publication $record): string => $this->statusOf($record))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'scheduled' => 'info',
                        'archived' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('when')
                    ->label('Date')
                    ->state(fn (Publication $record) => $record->published_at ?? $record->scheduled_at ?? $record->updated_at)
                    ->description(fn (Publication $record): string => match (true) {
                        $record->published_at !== null => 'Published',
                        $record->scheduled_at !== null => 'Scheduled for',
                        default => 'Last edited',
                    }, position: 'above')
                    ->dateTime('D j M, H:i'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'draft' => $query->where('state', 'draft')->whereNull('scheduled_at'),
                        'scheduled' => $query->where(fn (Builder $scheduled): Builder => $scheduled->where('state', 'scheduled')->orWhere(fn (Builder $draft): Builder => $draft->where('state', 'draft')->whereNotNull('scheduled_at'))),
                        null, '' => $query,
                        default => $query->where('state', $data['value']),
                    }),
                SelectFilter::make('kind')
                    ->options(['post' => 'Post', 'article' => 'Article']),
                SelectFilter::make('audience')
                    ->options(self::AUDIENCES),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema(fn (Schema $schema): Schema => $this->detailsSchema($schema)),
                Action::make('publishNow')
                    ->label('Publish')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->button()
                    ->requiresConfirmation()
                    ->modalDescription('Once published it can no longer be edited.')
                    ->visible(fn (Publication $record): bool => $this->isEditable($record))
                    ->action(fn (Publication $record) => $this->attempt(
                        fn () => app(PublishPublication::class)->handle($this->viewer(), $record),
                        'Published',
                    )),
                ActionGroup::make([
                    EditAction::make()
                        ->visible(fn (Publication $record): bool => $this->isEditable($record))
                        ->schema(fn (Schema $schema): Schema => $schema->components($this->formComponents(withTiming: false)))
                        ->fillForm(fn (Publication $record): array => $this->formStateOf($record))
                        ->using(fn (Publication $record, array $data): Publication => $this->attempt(function () use ($record, $data): Publication {
                            $updated = app(UpdatePublication::class)->handle($this->viewer(), $record, $this->contentAttributes($data));

                            return app(UpdatePublicationEnrichments::class)->handle($this->viewer(), $updated, $this->enrichments($data));
                        })),
                    Action::make('schedule')
                        ->label(fn (Publication $record): string => $record->scheduled_at === null ? 'Schedule' : 'Reschedule')
                        ->icon('heroicon-o-clock')
                        ->visible(fn (Publication $record): bool => $this->isEditable($record))
                        ->fillForm(fn (Publication $record): array => ['scheduled_at' => $record->scheduled_at])
                        ->schema([
                            DateTimePicker::make('scheduled_at')
                                ->label('Publish on')
                                ->seconds(false)
                                ->required()
                                ->after('now'),
                        ])
                        ->action(fn (Publication $record, array $data) => $this->attempt(
                            fn () => app(UpdatePublication::class)->handle($this->viewer(), $record, ['scheduled_at' => $data['scheduled_at']]),
                            'Scheduled',
                        )),
                    Action::make('unschedule')
                        ->label('Cancel schedule')
                        ->icon('heroicon-o-x-circle')
                        ->visible(fn (Publication $record): bool => $this->isEditable($record) && $record->scheduled_at !== null)
                        ->action(fn (Publication $record) => $this->attempt(
                            fn () => app(UpdatePublication::class)->handle($this->viewer(), $record, ['scheduled_at' => null]),
                            'Back to draft',
                        )),
                    Action::make('delete')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Publication $record) => $this->attempt(
                            fn () => app(DeletePublication::class)->handle($this->viewer(), $record),
                            'Publication deleted',
                        )),
                ])
                    ->label('More')
                    ->icon('heroicon-o-ellipsis-vertical'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('Nothing published yet')
            ->emptyStateDescription('Write your first post with the “New publication” button.')
            ->emptyStateIcon('heroicon-o-pencil-square')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(Publication::class)
                ->label('New publication')
                ->icon('heroicon-o-pencil-square')
                ->modalWidth('3xl')
                ->schema(fn (Schema $schema): Schema => $schema->components($this->formComponents(withTiming: true)))
                ->using(fn (array $data): Publication => $this->attempt(function () use ($data): Publication {
                    $publication = app(CreatePublication::class)->handle($this->viewer(), $this->contentAttributes($data));
                    $publication = app(UpdatePublicationEnrichments::class)->handle($this->viewer(), $publication, $this->enrichments($data));

                    return match ($data['timing'] ?? 'now') {
                        'now' => app(PublishPublication::class)->handle($this->viewer(), $publication),
                        'schedule' => app(UpdatePublication::class)->handle($this->viewer(), $publication, ['scheduled_at' => $data['scheduled_at']]),
                        default => $publication,
                    };
                })),
        ];
    }

    /** @return list<mixed> */
    private function formComponents(bool $withTiming): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('kind')
                    ->options(['post' => 'Post', 'article' => 'Article'])
                    ->default('post')
                    ->required()
                    ->live(),
                Select::make('audience')
                    ->label('Who can see it')
                    ->options(self::AUDIENCES)
                    ->default('public')
                    ->required(),
            ]),
            TextInput::make('title')
                ->maxLength(240)
                ->required(fn (Get $get): bool => $get('kind') === 'article')
                ->helperText(fn (Get $get): ?string => $get('kind') === 'article' ? null : 'Optional for posts.'),
            Textarea::make('body')
                ->label(fn (Get $get): string => $get('kind') === 'article' ? 'Article' : 'What do you want to share?')
                ->rows(fn (Get $get): int => $get('kind') === 'article' ? 10 : 4)
                ->required(fn (Get $get): bool => blank($get('title'))),
            Section::make('Extras')
                ->description('Mentions, hashtags, links and a poll.')
                ->collapsible()
                ->collapsed()
                ->compact()
                ->schema([
                    Select::make('mentions')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => app(ResolveDisplayNames::class)->search($search, [(string) $this->viewer()->getKey()]))
                        ->getOptionLabelsUsing(fn (array $values): array => app(ResolveDisplayNames::class)->handle(array_map('strval', $values))),
                    TagsInput::make('hashtags')
                        ->placeholder('#senderismo')
                        ->splitKeys(['Tab', ' ', ',']),
                    Repeater::make('links')
                        ->schema([
                            TextInput::make('url')->url()->required()->maxLength(2048),
                            TextInput::make('title')->maxLength(240),
                        ])
                        ->columns(2)
                        ->maxItems(20)
                        ->defaultItems(0)
                        ->addActionLabel('Add link'),
                    TagsInput::make('poll_options')
                        ->label('Poll options')
                        ->placeholder('Add an option and press Enter')
                        ->helperText('Leave empty for no poll. A poll needs 2 to 20 options.')
                        ->rules(['nullable', 'array', 'max:20'])
                        ->live(),
                    DateTimePicker::make('poll_closes_at')
                        ->label('Poll closes')
                        ->seconds(false)
                        ->visible(fn (Get $get): bool => count((array) $get('poll_options')) > 0),
                ]),
            ...($withTiming ? [
                Radio::make('timing')
                    ->label('When')
                    ->options(['now' => 'Publish now', 'schedule' => 'Schedule', 'draft' => 'Save as draft'])
                    ->default('now')
                    ->inline()
                    ->required()
                    ->live(),
                DateTimePicker::make('scheduled_at')
                    ->label('Publish on')
                    ->seconds(false)
                    ->after('now')
                    ->visible(fn (Get $get): bool => $get('timing') === 'schedule')
                    ->required(fn (Get $get): bool => $get('timing') === 'schedule'),
            ] : []),
        ];
    }

    private function detailsSchema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('kind')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextEntry::make('audience')->badge()->formatStateUsing(fn (string $state): string => self::AUDIENCES[$state] ?? $state),
                        TextEntry::make('status')
                            ->badge()
                            ->state(fn (Publication $record): string => ucfirst($this->statusOf($record))),
                    ]),
                    TextEntry::make('title')->placeholder('No title')->weight('bold'),
                    TextEntry::make('body')->hiddenLabel()->placeholder('No text')->columnSpanFull(),
                ]),
            Section::make('Extras')
                ->compact()
                ->schema([
                    TextEntry::make('mention_names')
                        ->label('Mentions')
                        ->badge()
                        ->state(fn (Publication $record): array => array_values(app(ResolveDisplayNames::class)->handle($this->mentionIds($record))))
                        ->placeholder('—'),
                    TextEntry::make('tags')
                        ->label('Hashtags')
                        ->badge()
                        ->state(fn (Publication $record): array => array_map(fn (string $tag): string => '#'.$tag, $this->hashtags($record)))
                        ->placeholder('—'),
                    RepeatableEntry::make('link_list')
                        ->label('Links')
                        ->state(fn (Publication $record): array => $this->links($record))
                        ->schema([
                            TextEntry::make('url')->hiddenLabel()->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)->color('primary'),
                        ])
                        ->placeholder('—'),
                    TextEntry::make('poll')
                        ->state(fn (Publication $record): array => $this->poll($record)['options'] ?? [])
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('—'),
                    TextEntry::make('edits')
                        ->label('Edit history')
                        ->state(fn (Publication $record): string => ($count = DB::table('social_publication_edits')->where('publication_id', $record->getKey())->count()) === 0 ? 'Never edited' : "Edited {$count} ".str('time')->plural($count)),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contentAttributes(array $data): array
    {
        return [
            'kind' => $data['kind'] ?? 'post',
            'audience' => $data['audience'] ?? 'public',
            'title' => filled($data['title'] ?? null) ? $data['title'] : null,
            'body' => filled($data['body'] ?? null) ? $data['body'] : null,
        ];
    }

    /**
     * The form state as UpdatePublicationEnrichments expects it. The poll key is
     * only sent with options, because the action has no way to remove a poll.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function enrichments(array $data): array
    {
        $enrichments = [
            'mentions' => array_values((array) ($data['mentions'] ?? [])),
            'hashtags' => array_values((array) ($data['hashtags'] ?? [])),
            'links' => array_values(array_map(fn (array $link): array => ['url' => $link['url'], 'title' => $link['title'] ?? null], (array) ($data['links'] ?? []))),
        ];

        $options = array_values(array_filter((array) ($data['poll_options'] ?? [])));
        if ($options !== []) {
            $enrichments['poll'] = ['options' => $options, 'closes_at' => $data['poll_closes_at'] ?? null];
        }

        return $enrichments;
    }

    /** @return array<string, mixed> */
    private function formStateOf(Publication $publication): array
    {
        $poll = $this->poll($publication);

        return [
            ...$publication->only(['kind', 'audience', 'title', 'body']),
            'mentions' => $this->mentionIds($publication),
            'hashtags' => $this->hashtags($publication),
            'links' => $this->links($publication),
            'poll_options' => $poll['options'] ?? [],
            'poll_closes_at' => $poll['closes_at'] ?? null,
        ];
    }

    /** Drafts and scheduled drafts can still be changed; published ones cannot. */
    private function isEditable(Publication $publication): bool
    {
        return in_array($publication->state, ['draft', 'scheduled'], true);
    }

    /** The backend keeps scheduled publications as drafts with a date. */
    private function statusOf(Publication $publication): string
    {
        return $publication->state === 'draft' && $publication->scheduled_at !== null ? 'scheduled' : (string) $publication->state;
    }

    /** @return list<string> */
    private function mentionIds(Publication $publication): array
    {
        return DB::table('social_publication_mentions')->where('publication_id', $publication->getKey())
            ->pluck('profile_id')->map(fn (mixed $id): string => (string) $id)->all();
    }

    /** @return list<string> */
    private function hashtags(Publication $publication): array
    {
        return DB::table('social_publication_hashtags')->where('publication_id', $publication->getKey())
            ->orderBy('id')->pluck('tag')->map(fn (mixed $tag): string => (string) $tag)->all();
    }

    /** @return list<array{url: string, title: ?string}> */
    private function links(Publication $publication): array
    {
        return DB::table('social_publication_links')->where('publication_id', $publication->getKey())
            ->orderBy('id')->get(['url', 'title'])
            ->map(fn (object $link): array => ['url' => (string) $link->url, 'title' => $link->title])->all();
    }

    /** @return array{options: list<string>, closes_at: ?string}|null */
    private function poll(Publication $publication): ?array
    {
        $poll = DB::table('social_publication_polls')->where('publication_id', $publication->getKey())->first();

        return $poll === null ? null : [
            'options' => array_values((array) json_decode((string) $poll->options, true)),
            'closes_at' => $poll->closes_at,
        ];
    }

    /**
     * Runs a module action and turns its refusals into a notification instead
     * of an error page.
     */
    private function attempt(Closure $callback, ?string $success = null): mixed
    {
        try {
            $result = DB::transaction($callback);
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
