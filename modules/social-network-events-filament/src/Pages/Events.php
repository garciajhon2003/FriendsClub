<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Events\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Liberu\SocialNetwork\Events\Actions\AddEventUpdate;
use Liberu\SocialNetwork\Events\Actions\CreateEvent;
use Liberu\SocialNetwork\Events\Actions\InviteProfile;
use Liberu\SocialNetwork\Events\Actions\PublishEvent;
use Liberu\SocialNetwork\Events\Actions\ScheduleReminder;
use Liberu\SocialNetwork\Events\Actions\SetAttendance;
use Liberu\SocialNetwork\Events\Actions\UpdateEvent;
use Liberu\SocialNetwork\Events\Filament\Contracts\EventsPageExtension;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Events\Models\EventUpdate;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Actions\ResolveDisplayNames;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Events table for the member panel. Every write goes through the actions of
 * social-network-events, so their validation and gates still apply.
 */
final class Events extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'social-network-events-filament::pages.events';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    private ?Profile $viewer = null;

    /** @var list<EventsPageExtension>|null */
    private ?array $extensions = null;

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    /** @return list<EventsPageExtension> */
    private function extensions(): array
    {
        return $this->extensions ??= array_values(iterator_to_array(app()->tagged(EventsPageExtension::TAG)));
    }

    private function isOwner(Event $event): bool
    {
        return (string) $event->owner_profile_id === (string) $this->viewer()->getKey();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->visibleEvents())
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Event $record): ?string => $record->location['name'] ?? null),
                ...$this->extended(fn (EventsPageExtension $extension): array => $extension->columns()),
                TextColumn::make('starts_at')
                    ->label('When')
                    ->dateTime('D j M, H:i')
                    ->sortable(),
                TextColumn::make('going_count')
                    ->label('Going')
                    ->state(fn (Event $record): string => $this->spotsLabel($record)),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'gray',
                        'cancelled' => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('my_attendance')
                    ->label('You')
                    ->badge()
                    ->state(fn (Event $record): ?string => $this->isOwner($record) ? 'organizer' : $record->my_attendance)
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'organizer' => 'Organizer',
                        'going' => 'Going',
                        'maybe' => 'Maybe',
                        'not_going' => 'Not going',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'organizer' => 'primary',
                        'going' => 'success',
                        'maybe' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),
            ])
            ->filters([
                Filter::make('upcoming')
                    ->label('Upcoming only')
                    ->query(fn (Builder $query): Builder => $query->where('starts_at', '>=', now()))
                    ->default(),
                ...$this->extended(fn (EventsPageExtension $extension): array => $extension->filters()),
                SelectFilter::make('state')
                    ->options(['draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled', 'completed' => 'Completed']),
                TernaryFilter::make('mine')
                    ->label('Organized by me')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('owner_profile_id', $this->viewer()->getKey()),
                        false: fn (Builder $query): Builder => $query->where('owner_profile_id', '!=', $this->viewer()->getKey()),
                    ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema(fn (Schema $schema): Schema => $this->detailsSchema($schema)),
                $this->attendanceActions(),
                $this->organizerActions(),
            ])
            ->defaultSort('starts_at')
            ->emptyStateHeading('No events yet')
            ->emptyStateDescription('Create the first one with the “New event” button.')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(Event::class)
                ->label('New event')
                ->icon('heroicon-o-plus')
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    ...$this->formComponents(),
                    Toggle::make('publish_now')
                        ->label('Publish now')
                        ->helperText('Off: it stays as a draft that only you can see.')
                        ->default(true)
                        ->columnSpanFull(),
                ])->columns(2))
                ->using(fn (array $data): Event => $this->attempt(function () use ($data): Event {
                    $event = app(CreateEvent::class)->handle($this->viewer(), $this->eventAttributes($data));
                    $event->forceFill(['visibility' => $data['visibility'] ?? 'public', 'timezone' => config('app.timezone')])->save();
                    $this->notifySaved($event, $data);

                    return ($data['publish_now'] ?? false)
                        ? app(PublishEvent::class)->handle($this->viewer(), $event)
                        : $event;
                })),
        ];
    }

    /**
     * Same visibility rule as ListEvents (public published events, my own, or
     * those I was invited to), as a query the table can filter and paginate.
     */
    private function visibleEvents(): Builder
    {
        $profileId = $this->viewer()->getKey();
        $eagerLoad = array_merge(...array_map(fn (EventsPageExtension $extension): array => $extension->eagerLoad(), $this->extensions()) ?: [[]]);

        return Event::query()
            ->with($eagerLoad)
            ->select('social_events.*')
            ->addSelect([
                'going_count' => DB::table('social_event_attendance')
                    ->selectRaw('count(*)')
                    ->whereColumn('social_event_attendance.event_id', 'social_events.id')
                    ->where('social_event_attendance.state', 'going'),
                'my_attendance' => DB::table('social_event_attendance')
                    ->select('state')
                    ->whereColumn('social_event_attendance.event_id', 'social_events.id')
                    ->where('social_event_attendance.profile_id', $profileId)
                    ->limit(1),
            ])
            ->where(function (Builder $query) use ($profileId): void {
                $query->where(function (Builder $published) use ($profileId): void {
                    $published->whereIn('state', ['published', 'completed'])
                        ->where(function (Builder $visible) use ($profileId): void {
                            $visible->where('visibility', 'public')
                                ->orWhereExists(function ($invited) use ($profileId): void {
                                    $invited->selectRaw('1')
                                        ->from('social_event_invitations')
                                        ->whereColumn('social_event_invitations.event_id', 'social_events.id')
                                        ->where('social_event_invitations.profile_id', $profileId)
                                        ->whereIn('social_event_invitations.state', ['pending', 'accepted']);
                                });
                        });
                })->orWhere('owner_profile_id', $profileId);
            });
    }

    /** @return list<mixed> */
    private function formComponents(): array
    {
        return [
            TextInput::make('title')
                ->required()
                ->maxLength((int) config('social-network-events.max_title_length', 200))
                ->columnSpanFull(),
            Select::make('visibility')
                ->options(['public' => 'Public', 'private' => 'Private (invited only)'])
                ->default('public')
                ->required(),
            TextInput::make('capacity')
                ->label('Spots')
                ->numeric()
                ->minValue(1)
                ->helperText('Leave blank for unlimited.'),
            DateTimePicker::make('starts_at')
                ->label('Starts')
                ->seconds(false)
                ->required(),
            DateTimePicker::make('ends_at')
                ->label('Ends')
                ->seconds(false)
                ->after('starts_at'),
            TextInput::make('location_name')
                ->label('Place')
                ->placeholder('A café, a park — or "Online"')
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('description')
                ->maxLength((int) config('social-network-events.max_description_length', 20000))
                ->rows(4)
                ->columnSpanFull(),
            ...$this->extended(fn (EventsPageExtension $extension): array => $extension->formComponents()),
        ];
    }

    private function detailsSchema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('starts_at')->label('Starts')->dateTime('D j M Y, H:i'),
                        TextEntry::make('ends_at')->label('Ends')->dateTime('D j M Y, H:i')->placeholder('—'),
                        TextEntry::make('location.name')->label('Place')->placeholder('—'),
                        TextEntry::make('spots')
                            ->label('Going')
                            ->state(fn (Event $record): string => $this->spotsLabel($record)),
                        TextEntry::make('organizer')
                            ->state(fn (Event $record): string => $this->profileNames([(string) $record->owner_profile_id])[0] ?? '—'),
                        ...$this->extended(fn (EventsPageExtension $extension): array => $extension->detailEntries()),
                    ]),
                    TextEntry::make('description')->placeholder('No description.')->columnSpanFull(),
                ]),
            Section::make('Attendees')
                ->schema([
                    TextEntry::make('attendees')
                        ->hiddenLabel()
                        ->state(fn (Event $record): array => $this->attendeeNames($record))
                        ->badge()
                        ->placeholder('Nobody has confirmed yet.'),
                ]),
            Section::make('Updates from the organizer')
                ->schema([
                    RepeatableEntry::make('event_updates')
                        ->hiddenLabel()
                        ->state(fn (Event $record): array => EventUpdate::query()
                            ->where('event_id', $record->getKey())
                            ->latest()
                            ->get()
                            ->map(fn (EventUpdate $update): array => ['body' => $update->body, 'created_at' => $update->created_at])
                            ->all())
                        ->schema([
                            TextEntry::make('body')->hiddenLabel(),
                            TextEntry::make('created_at')->hiddenLabel()->since()->size('sm')->color('gray'),
                        ])
                        ->placeholder('No updates yet.'),
                ]),
        ]);
    }

    /** "I'm going / Maybe / Can't go" and reminders, on published events that have not started. */
    private function attendanceActions(): ActionGroup
    {
        $attendance = fn (string $state, string $label, string $icon, string $color): Action => Action::make("attend_{$state}")
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->hidden(fn (Event $record): bool => $record->my_attendance === $state)
            ->action(fn (Event $record) => $this->attempt(
                fn () => app(SetAttendance::class)->handle($this->viewer(), $record, $state),
                'Attendance saved',
            ));

        return ActionGroup::make([
            $attendance('going', "I'm going", 'heroicon-o-check-circle', 'success'),
            $attendance('maybe', 'Maybe', 'heroicon-o-question-mark-circle', 'warning'),
            $attendance('not_going', "Can't go", 'heroicon-o-x-circle', 'gray'),
            Action::make('remind')
                ->label('Remind me')
                ->icon('heroicon-o-bell-alert')
                ->schema([
                    DateTimePicker::make('send_at')
                        ->label('Send the reminder at')
                        ->seconds(false)
                        ->required()
                        ->after('now')
                        ->default(fn (Event $record) => $record->starts_at->copy()->subDay()),
                ])
                ->visible(fn (Event $record): bool => in_array($record->my_attendance, ['going', 'maybe'], true))
                ->action(fn (Event $record, array $data) => $this->attempt(
                    fn () => app(ScheduleReminder::class)->handle($this->viewer(), $record, (string) $data['send_at']),
                    'Reminder scheduled',
                )),
        ])
            ->label('Attend')
            ->icon('heroicon-o-hand-raised')
            ->button()
            ->color('success')
            ->visible(fn (Event $record): bool => $record->state === 'published' && $record->starts_at->isFuture());
    }

    /** Edit, publish, invite, post updates and cancel: only for the organizer. */
    private function organizerActions(): ActionGroup
    {
        return ActionGroup::make([
            EditAction::make()
                ->schema(fn (Schema $schema): Schema => $schema->components($this->formComponents())->columns(2))
                ->fillForm(fn (Event $record): array => [
                    ...$record->only(['title', 'visibility', 'capacity', 'starts_at', 'ends_at', 'description']),
                    'location_name' => $record->location['name'] ?? null,
                    ...array_merge(...array_map(fn (EventsPageExtension $extension): array => $extension->fillForm($record), $this->extensions()) ?: [[]]),
                ])
                ->using(fn (Event $record, array $data): Event => $this->attempt(function () use ($record, $data): Event {
                    $event = app(UpdateEvent::class)->handle($this->viewer(), $record, $this->eventAttributes($data));
                    $this->notifySaved($event, $data);

                    return $event;
                })),
            Action::make('publish')
                ->icon('heroicon-o-megaphone')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Once published, other members can see the event and sign up.')
                ->visible(fn (Event $record): bool => $record->state === 'draft')
                ->action(fn (Event $record) => $this->attempt(
                    fn () => app(PublishEvent::class)->handle($this->viewer(), $record),
                    'Event published',
                )),
            Action::make('invite')
                ->icon('heroicon-o-user-plus')
                ->schema([
                    Select::make('profiles')
                        ->label('People')
                        ->multiple()
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search, Event $record): array => $this->inviteeOptions($record, $search))
                        ->getOptionLabelsUsing(fn (array $values): array => array_combine($values, $this->profileNames($values))),
                ])
                ->visible(fn (Event $record): bool => in_array($record->state, ['draft', 'published'], true))
                ->action(fn (Event $record, array $data) => $this->attempt(function () use ($record, $data): void {
                    foreach (Profile::query()->whereKey($data['profiles'])->get() as $invitee) {
                        app(InviteProfile::class)->handle($this->viewer(), $record, $invitee);
                    }
                }, 'Invitations sent')),
            Action::make('postUpdate')
                ->label('Post update')
                ->icon('heroicon-o-chat-bubble-bottom-center-text')
                ->schema([
                    Textarea::make('body')
                        ->label('Message for attendees')
                        ->required()
                        ->maxLength(20000),
                ])
                ->action(fn (Event $record, array $data) => $this->attempt(
                    fn () => app(AddEventUpdate::class)->handle($this->viewer(), $record, $data['body']),
                    'Update posted',
                )),
            Action::make('cancel')
                ->label('Cancel event')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Event $record): bool => in_array($record->state, ['draft', 'published'], true))
                ->action(fn (Event $record) => $this->attempt(function () use ($record): void {
                    Gate::authorize('social-network.events.manage', [$this->viewer(), $record]);
                    $record->update(['state' => 'cancelled']);
                }, 'Event cancelled')),
        ])
            ->label('Manage')
            ->icon('heroicon-o-cog-6-tooth')
            ->button()
            ->visible(fn (Event $record): bool => $this->isOwner($record));
    }

    /**
     * Maps the form state to what CreateEvent / UpdateEvent expect.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function eventAttributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'description' => filled($data['description'] ?? null) ? $data['description'] : null,
            'starts_at' => $data['starts_at'],
            'ends_at' => filled($data['ends_at'] ?? null) ? $data['ends_at'] : null,
            'capacity' => filled($data['capacity'] ?? null) ? (int) $data['capacity'] : null,
            'location' => filled($data['location_name'] ?? null) ? ['name' => $data['location_name']] : [],
            'visibility' => $data['visibility'] ?? 'public',
            'timezone' => config('app.timezone'),
        ];
    }

    /** @param array<string, mixed> $data */
    private function notifySaved(Event $event, array $data): void
    {
        foreach ($this->extensions() as $extension) {
            $extension->saved($event, $data);
        }
    }

    /**
     * @param  Closure(EventsPageExtension): list<mixed>  $contribution
     * @return list<mixed>
     */
    private function extended(Closure $contribution): array
    {
        return array_merge(...array_map($contribution, $this->extensions()) ?: [[]]);
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

    private function spotsLabel(Event $record): string
    {
        $going = (int) ($record->going_count ?? DB::table('social_event_attendance')
            ->where(['event_id' => $record->getKey(), 'state' => 'going'])
            ->count());

        return $record->capacity === null ? (string) $going : "{$going} / {$record->capacity}";
    }

    /** @return list<string> */
    private function attendeeNames(Event $record): array
    {
        return $this->profileNames(DB::table('social_event_attendance')
            ->where(['event_id' => $record->getKey(), 'state' => 'going'])
            ->pluck('profile_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * Display names, in the same order as $profileIds.
     *
     * @param  list<string>  $profileIds
     * @return list<string>
     */
    private function profileNames(array $profileIds): array
    {
        return array_values(app(ResolveDisplayNames::class)->handle($profileIds));
    }

    /** @return array<string, string> */
    private function inviteeOptions(Event $record, string $search): array
    {
        return app(ResolveDisplayNames::class)->search($search, [(string) $record->owner_profile_id]);
    }
}
