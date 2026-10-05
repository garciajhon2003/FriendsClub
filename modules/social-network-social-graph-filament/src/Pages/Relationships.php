<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\SocialGraph\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\SocialGraph\Actions\AcceptFriendship;
use Liberu\SocialNetwork\SocialGraph\Actions\AddProfileToList;
use Liberu\SocialNetwork\SocialGraph\Actions\BlockProfile;
use Liberu\SocialNetwork\SocialGraph\Actions\CancelFriendship;
use Liberu\SocialNetwork\SocialGraph\Actions\CreateRelationship;
use Liberu\SocialNetwork\SocialGraph\Actions\RejectFriendship;
use Liberu\SocialNetwork\SocialGraph\Actions\UpdateRelationshipVisibility;
use Liberu\SocialNetwork\SocialGraph\Filament\Pages\Concerns\InteractsWithGraph;
use Liberu\SocialNetwork\SocialGraph\Models\GraphList;
use Liberu\SocialNetwork\SocialGraph\Models\Relationship;

/**
 * "People": who I follow, who follows me, my friends and friend requests.
 * Every write goes through the actions of social-network-social-graph.
 */
final class Relationships extends Page implements HasTable
{
    use InteractsWithGraph;
    use InteractsWithTable;

    /** @var array<string, array{label: string, color: string}> */
    private const array KINDS = [
        'following' => ['label' => 'You follow', 'color' => 'info'],
        'follower' => ['label' => 'Follows you', 'color' => 'gray'],
        'friend' => ['label' => 'Friend', 'color' => 'success'],
        'request_received' => ['label' => 'Wants to be friends', 'color' => 'warning'],
        'request_sent' => ['label' => 'Friend request sent', 'color' => 'gray'],
    ];

    /** @var array<string, string> */
    private const array VISIBILITIES = ['public' => 'Everyone', 'followers' => 'Followers', 'private' => 'Only me'];

    protected string $view = 'social-network-social-graph-filament::pages.relationships';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'People';

    protected static ?string $navigationLabel = 'Relationships';

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->check()) {
            return null;
        }

        $requests = Relationship::query()
            ->where('target_profile_id', app(GetProfile::class)->forUser(auth()->id())->getKey())
            ->where(['relationship_type' => 'friend', 'status' => 'pending'])
            ->count();

        return $requests > 0 ? (string) $requests : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->myRelationships())
            ->columns([
                TextColumn::make('person')
                    ->state(fn (Relationship $record): string => $this->nameOf($this->otherSide($record)))
                    ->weight('bold'),
                TextColumn::make('kind')
                    ->label('Relationship')
                    ->badge()
                    ->state(fn (Relationship $record): string => $this->kindOf($record))
                    ->formatStateUsing(fn (string $state): string => self::KINDS[$state]['label'])
                    ->color(fn (string $state): string => self::KINDS[$state]['color']),
                TextColumn::make('visibility')
                    ->label('Who can see it')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Relationship $record): ?string => $this->isMine($record) ? $record->visibility : null)
                    ->formatStateUsing(fn (string $state): string => self::VISIBILITIES[$state] ?? $state)
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label('Since')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Show')
                    ->options(collect(self::KINDS)->map(fn (array $kind): string => $kind['label'])->all())
                    ->query(fn (Builder $query, array $data): Builder => $this->onlyKind($query, $data['value'] ?? null)),
            ])
            ->recordActions([
                Action::make('accept')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->button()
                    ->visible(fn (Relationship $record): bool => $this->kindOf($record) === 'request_received')
                    ->action(fn (Relationship $record) => $this->attempt(
                        fn () => app(AcceptFriendship::class)->handle($this->viewer(), $record),
                        'You are now friends',
                    )),
                Action::make('reject')
                    ->label('Decline')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (Relationship $record): bool => $this->kindOf($record) === 'request_received')
                    ->action(fn (Relationship $record) => $this->attempt(
                        fn () => app(RejectFriendship::class)->handle($this->viewer(), $record),
                        'Request declined',
                    )),
                Action::make('cancelRequest')
                    ->label('Cancel request')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (Relationship $record): bool => $this->kindOf($record) === 'request_sent')
                    ->action(fn (Relationship $record) => $this->attempt(
                        fn () => app(CancelFriendship::class)->handle($this->viewer(), $record),
                        'Request cancelled',
                    )),
                ActionGroup::make([
                    Action::make('visibility')
                        ->label('Who can see it')
                        ->icon('heroicon-o-eye')
                        ->visible(fn (Relationship $record): bool => $this->isMine($record))
                        ->fillForm(fn (Relationship $record): array => ['visibility' => $record->visibility])
                        ->schema([
                            Select::make('visibility')
                                ->options(self::VISIBILITIES)
                                ->required(),
                        ])
                        ->action(fn (Relationship $record, array $data) => $this->attempt(
                            fn () => app(UpdateRelationshipVisibility::class)->handle($this->viewer(), $record, $data['visibility']),
                            'Saved',
                        )),
                    Action::make('addToList')
                        ->label('Add to a list')
                        ->icon('heroicon-o-queue-list')
                        ->visible(fn (): bool => $this->myListOptions() !== [])
                        ->schema([
                            Select::make('list')
                                ->options(fn (): array => $this->myListOptions())
                                ->required(),
                        ])
                        ->action(fn (Relationship $record, array $data) => $this->attempt(
                            fn () => app(AddProfileToList::class)->handle(
                                $this->viewer(),
                                GraphList::query()->findOrFail($data['list']),
                                $this->profile($this->otherSide($record)),
                            ),
                            'Added to the list',
                        )),
                    Action::make('block')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('You will stop following each other and any friendship or request between you is removed.')
                        ->action(fn (Relationship $record) => $this->attempt(
                            fn () => app(BlockProfile::class)->handle($this->viewer(), $this->profile($this->otherSide($record))),
                            'Blocked',
                        )),
                ])
                    ->label('More')
                    ->icon('heroicon-o-ellipsis-vertical'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No connections yet')
            ->emptyStateDescription('Follow people or send friend requests, or have a look at the suggestions.')
            ->emptyStateIcon('heroicon-o-user-group')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('follow')
                ->label('Follow someone')
                ->icon('heroicon-o-user-plus')
                ->schema([$this->personPicker()])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(CreateRelationship::class)->follow($this->viewer(), $this->profile($data['profile'])),
                    'Following',
                )),
            Action::make('befriend')
                ->label('Add a friend')
                ->icon('heroicon-o-hand-raised')
                ->color('gray')
                ->schema([$this->personPicker()])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(CreateRelationship::class)->friend($this->viewer(), $this->profile($data['profile'])),
                    'Friend request sent',
                )),
            Action::make('suggestions')
                ->label('Suggestions')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->url(fn (): string => Suggestions::getUrl()),
        ];
    }

    /**
     * Live relationships I am part of, hiding anyone a block stands between.
     * Relationship::visibleTo() is not used: it is meant for looking at someone
     * else's graph, and would hide my own followers and the friend requests I
     * receive unless I happened to follow those people back.
     */
    private function myRelationships(): Builder
    {
        $me = (string) $this->viewer()->getKey();

        return Relationship::query()
            ->where(fn (Builder $query): Builder => $query->where('source_profile_id', $me)->orWhere('target_profile_id', $me))
            ->whereIn('status', ['pending', 'accepted'])
            ->whereNotExists(fn ($blocks) => $blocks
                ->selectRaw('1')
                ->from('social_graph_blocks')
                ->where(fn ($pair) => $pair
                    ->where(fn ($mine) => $mine
                        ->whereColumn('social_graph_blocks.source_profile_id', 'social_graph_relationships.source_profile_id')
                        ->whereColumn('social_graph_blocks.target_profile_id', 'social_graph_relationships.target_profile_id'))
                    ->orWhere(fn ($theirs) => $theirs
                        ->whereColumn('social_graph_blocks.source_profile_id', 'social_graph_relationships.target_profile_id')
                        ->whereColumn('social_graph_blocks.target_profile_id', 'social_graph_relationships.source_profile_id'))));
    }

    private function onlyKind(Builder $query, ?string $kind): Builder
    {
        $me = (string) $this->viewer()->getKey();

        return match ($kind) {
            'following' => $query->where(['relationship_type' => 'follow', 'source_profile_id' => $me]),
            'follower' => $query->where(['relationship_type' => 'follow', 'target_profile_id' => $me]),
            'friend' => $query->where(['relationship_type' => 'friend', 'status' => 'accepted']),
            'request_received' => $query->where(['relationship_type' => 'friend', 'status' => 'pending', 'target_profile_id' => $me]),
            'request_sent' => $query->where(['relationship_type' => 'friend', 'status' => 'pending', 'source_profile_id' => $me]),
            default => $query,
        };
    }

    private function kindOf(Relationship $relationship): string
    {
        $mine = $this->isMine($relationship);

        return match (true) {
            $relationship->relationship_type === 'follow' => $mine ? 'following' : 'follower',
            $relationship->status === 'accepted' => 'friend',
            default => $mine ? 'request_sent' : 'request_received',
        };
    }

    private function isMine(Relationship $relationship): bool
    {
        return (string) $relationship->source_profile_id === (string) $this->viewer()->getKey();
    }

    private function otherSide(Relationship $relationship): string
    {
        return (string) ($this->isMine($relationship) ? $relationship->target_profile_id : $relationship->source_profile_id);
    }

    /** @return array<string, string> */
    private function myListOptions(): array
    {
        return GraphList::query()->where('owner_profile_id', $this->viewer()->getKey())->orderBy('name')->pluck('name', 'id')->all();
    }
}
