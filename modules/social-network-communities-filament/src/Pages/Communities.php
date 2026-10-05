<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Liberu\SocialNetwork\Communities\Actions\CreateCommunity;
use Liberu\SocialNetwork\Communities\Actions\JoinCommunity;
use Liberu\SocialNetwork\Communities\Actions\LeaveCommunity;
use Liberu\SocialNetwork\Communities\Enums\CommunityModality;
use Liberu\SocialNetwork\Communities\Filament\Contracts\CommunitiesPageExtension;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Actions\ResolveDisplayNames;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Communities table for the member panel. Every write goes through the actions
 * of social-network-communities, so their validation and gates still apply.
 */
final class Communities extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'social-network-communities-filament::pages.communities';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    /** Meeting days: 1 = Monday … 7 = Sunday. */
    private const array DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private ?Profile $viewer = null;

    /** @var list<CommunitiesPageExtension>|null */
    private ?array $extensions = null;

    /** Translatable, so a composition can call its communities something else. */
    public static function getNavigationLabel(): string
    {
        return __('Communities');
    }

    public function getTitle(): string
    {
        return __('Communities');
    }

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    /** @return list<CommunitiesPageExtension> */
    private function extensions(): array
    {
        return $this->extensions ??= array_values(iterator_to_array(app()->tagged(CommunitiesPageExtension::TAG)));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->visibleCommunities())
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Community $record): ?string => $record->description === null ? null : str($record->description)->limit(80)->toString()),
                ...$this->extended(fn (CommunitiesPageExtension $extension): array => $extension->columns()),
                TextColumn::make('modality')
                    ->label('Where')
                    ->state(fn (Community $record): string => $this->whereLabel($record)),
                TextColumn::make('meeting_day')
                    ->label('Meets')
                    ->state(fn (Community $record): ?string => $this->scheduleLabel($record))
                    ->placeholder('—'),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->formatStateUsing(fn (mixed $state, Community $record): string => $record->max_members === null ? (string) $state : $state.' / '.$record->max_members)
                    ->sortable(),
                TextColumn::make('visibility')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'public' ? 'success' : 'gray'),
                TextColumn::make('my_membership')
                    ->label('You')
                    ->badge()
                    ->state(fn (Community $record): ?string => $this->membershipState($record))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'owner' => 'Owner',
                        'member' => 'Member',
                        'pending' => 'Request sent',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'owner' => 'primary',
                        'member' => 'success',
                        default => 'warning',
                    })
                    ->placeholder('—'),
            ])
            ->filters([
                ...$this->extended(fn (CommunitiesPageExtension $extension): array => $extension->filters()),
                SelectFilter::make('modality')
                    ->options(CommunityModality::options()),
                SelectFilter::make('meeting_day')
                    ->label('Meeting day')
                    ->options(self::DAYS),
                SelectFilter::make('visibility')
                    ->options(['public' => 'Public', 'private' => 'Private']),
                TernaryFilter::make('mine')
                    ->label(__('My communities'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn('social_communities.id', $this->myCommunityIds()),
                        false: fn (Builder $query): Builder => $query->whereNotIn('social_communities.id', $this->myCommunityIds()),
                    ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema(fn (Schema $schema): Schema => $this->detailsSchema($schema)),
                Action::make('join')
                    ->label(fn (Community $record): string => $record->visibility === 'public' ? 'Join' : 'Request to join')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->button()
                    ->visible(fn (Community $record): bool => $record->my_status === null)
                    ->action(fn (Community $record) => $this->attempt(
                        fn () => app(JoinCommunity::class)->handle($this->viewer(), $record),
                        $record->visibility === 'public' ? __('Welcome to the community!') : __('Request sent to the owner'),
                    )),
                Action::make('leave')
                    ->label(fn (Community $record): string => $record->my_status === 'pending' ? 'Cancel request' : 'Leave')
                    ->icon('heroicon-o-arrow-left-start-on-rectangle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Community $record): bool => $record->my_status !== null && $record->my_role !== 'owner')
                    ->action(fn (Community $record) => $this->attempt(
                        fn () => app(LeaveCommunity::class)->handle($this->viewer(), $record),
                        __('You left the community'),
                    )),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('No communities yet'))
            ->emptyStateDescription(__('Create the first one with the “New community” button.'))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(Community::class)
                ->label(__('New community'))
                ->icon('heroicon-o-plus')
                ->schema(fn (Schema $schema): Schema => $schema->components($this->formComponents())->columns(2))
                ->using(fn (array $data): Community => $this->attempt(function () use ($data): Community {
                    $community = app(CreateCommunity::class)->handle($this->viewer(), [
                        'name' => $data['name'],
                        'description' => filled($data['description'] ?? null) ? $data['description'] : null,
                        'visibility' => $data['visibility'] ?? 'public',
                        'rules' => array_values($data['rules'] ?? []),
                        ...Arr::only($data, ['modality', 'city', 'latitude', 'longitude', 'max_members', 'meeting_day', 'meeting_time']),
                    ]);

                    foreach ($this->extensions() as $extension) {
                        $extension->saved($community, $data);
                    }

                    return $community;
                })),
        ];
    }

    /**
     * Same rule as ListCommunities (Community::visibleTo), with the
     * member count and my membership added for the table.
     */
    private function visibleCommunities(): Builder
    {
        $profileId = $this->viewer()->getKey();
        $myMembership = fn (string $column) => DB::table('social_community_memberships')
            ->select($column)
            ->whereColumn('social_community_memberships.community_id', 'social_communities.id')
            ->where('social_community_memberships.profile_id', $profileId)
            ->whereIn('social_community_memberships.status', ['pending', 'active'])
            ->limit(1);

        return Community::query()
            ->with(array_merge(...array_map(fn (CommunitiesPageExtension $extension): array => $extension->eagerLoad(), $this->extensions()) ?: [[]]))
            ->select('social_communities.*')
            ->addSelect([
                'members_count' => DB::table('social_community_memberships')
                    ->selectRaw('count(*)')
                    ->whereColumn('social_community_memberships.community_id', 'social_communities.id')
                    ->where('social_community_memberships.status', 'active'),
                'my_status' => $myMembership('status'),
                'my_role' => $myMembership('role'),
            ])
            ->visibleTo($this->viewer());
    }

    /** @return list<mixed> */
    private function formComponents(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(120)
                ->columnSpanFull(),
            Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),
            Select::make('visibility')
                ->options(['public' => 'Public (anyone can join)', 'private' => 'Private (join on request)'])
                ->default('public')
                ->required(),
            TagsInput::make('rules')
                ->placeholder('Add a rule and press Enter')
                ->helperText('e.g. "Be kind", "No spam"'),
            ...$this->extended(fn (CommunitiesPageExtension $extension): array => $extension->formComponents()),
            Select::make('modality')
                ->options(CommunityModality::options())
                ->default(CommunityModality::Online->value)
                ->required()
                ->live(),
            TextInput::make('city')
                ->maxLength(120)
                ->visible(fn (Get $get): bool => $get('modality') === CommunityModality::InPerson->value)
                ->required(fn (Get $get): bool => $get('modality') === CommunityModality::InPerson->value),
            TextInput::make('max_members')
                ->label('Spots')
                ->integer()
                ->minValue(CreateCommunity::MIN_MEMBERS)
                ->maxValue(CreateCommunity::MAX_MEMBERS)
                ->helperText('Leave blank for unlimited.'),
            TextInput::make('latitude')
                ->numeric()
                ->minValue(-90)
                ->maxValue(90)
                ->requiredWith('longitude')
                ->helperText('Optional: lets members find it by distance.')
                ->visible(fn (Get $get): bool => $get('modality') === CommunityModality::InPerson->value),
            TextInput::make('longitude')
                ->numeric()
                ->minValue(-180)
                ->maxValue(180)
                ->requiredWith('latitude')
                ->visible(fn (Get $get): bool => $get('modality') === CommunityModality::InPerson->value),
            Select::make('meeting_day')
                ->label('Meeting day')
                ->options(self::DAYS),
            TimePicker::make('meeting_time')
                ->label('Meeting time')
                ->seconds(false),
        ];
    }

    private function whereLabel(Community $record): string
    {
        $modality = CommunityModality::tryFrom((string) $record->modality);

        if ($modality === CommunityModality::InPerson) {
            return trim($modality->label().' · '.$record->city, ' ·');
        }

        return $modality?->label() ?? '—';
    }

    private function scheduleLabel(Community $record): ?string
    {
        $day = self::DAYS[(int) $record->meeting_day] ?? null;

        if ($day === null) {
            return null;
        }

        return $record->meeting_time === null ? $day : $day.' '.substr((string) $record->meeting_time, 0, 5);
    }

    private function detailsSchema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('owner')
                            ->state(fn (Community $record): string => $this->profileNames([(string) $record->owner_profile_id])[0] ?? '—'),
                        TextEntry::make('visibility')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextEntry::make('members_count')
                            ->label('Members')
                            ->formatStateUsing(fn (mixed $state, Community $record): string => $record->max_members === null ? (string) $state : $state.' / '.$record->max_members),
                        TextEntry::make('where')
                            ->state(fn (Community $record): string => $this->whereLabel($record)),
                        TextEntry::make('meets')
                            ->state(fn (Community $record): ?string => $this->scheduleLabel($record))
                            ->placeholder('—'),
                        ...$this->extended(fn (CommunitiesPageExtension $extension): array => $extension->detailEntries()),
                    ]),
                    TextEntry::make('description')->placeholder('No description.')->columnSpanFull(),
                    TextEntry::make('rules')
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('No rules yet.')
                        ->columnSpanFull(),
                ]),
            Section::make('Members')
                ->schema([
                    TextEntry::make('member_names')
                        ->hiddenLabel()
                        ->state(fn (Community $record): array => $this->memberNames($record, 'active'))
                        ->badge()
                        ->placeholder('No members yet.'),
                ]),
            Section::make('Pending requests')
                ->visible(fn (Community $record): bool => $record->my_role === 'owner' && $record->visibility === 'private')
                ->schema([
                    TextEntry::make('pending_names')
                        ->hiddenLabel()
                        ->state(fn (Community $record): array => $this->memberNames($record, 'pending'))
                        ->badge()
                        ->color('warning')
                        ->placeholder('No pending requests.'),
                ]),
        ]);
    }

    private function membershipState(Community $record): ?string
    {
        return match (true) {
            $record->my_role === 'owner' => 'owner',
            $record->my_status === 'active' => 'member',
            $record->my_status === 'pending' => 'pending',
            default => null,
        };
    }

    /** @return list<string> */
    private function myCommunityIds(): array
    {
        return DB::table('social_community_memberships')
            ->where('profile_id', $this->viewer()->getKey())
            ->whereIn('status', ['pending', 'active'])
            ->pluck('community_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /** @return list<string> */
    private function memberNames(Community $record, string $status): array
    {
        return $this->profileNames(DB::table('social_community_memberships')
            ->where(['community_id' => $record->getKey(), 'status' => $status])
            ->pluck('profile_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * @param  Closure(CommunitiesPageExtension): list<mixed>  $contribution
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
                ->title(__($exception instanceof AuthorizationException ? 'You are not allowed to do that.' : $exception->getMessage()))
                ->danger()
                ->send();

            throw new Halt();
        }

        if ($success !== null) {
            Notification::make()->title($success)->success()->send();
        }

        return $result;
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
}
