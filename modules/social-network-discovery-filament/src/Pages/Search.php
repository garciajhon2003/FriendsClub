<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Discovery\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Liberu\SocialNetwork\Discovery\Actions\ListTrends;
use Liberu\SocialNetwork\Discovery\Actions\SearchDiscovery;
use Liberu\SocialNetwork\Discovery\Models\DiscoveryIndex;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Livewire\Attributes\Url;

/**
 * Search across everything the discovery index holds (posts, clubs, events…),
 * with trending terms to start from. Results follow SearchDiscovery's rule:
 * public content, plus my own.
 */
final class Search extends Page implements HasTable
{
    use InteractsWithTable;

    private const int TRENDS_SHOWN = 12;

    protected string $view = 'social-network-discovery-filament::pages.search';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'Discover';

    protected static ?string $navigationLabel = 'Search';

    /** The search box, kept in the URL so a search can be shared or reloaded. */
    #[Url(as: 'q')]
    public $tableSearch = '';

    private ?Profile $viewer = null;

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    /** @return array<string, int> term => how many popular items use it */
    public function trends(): array
    {
        return array_slice(app(ListTrends::class)->handle($this->viewer(), 50), 0, self::TRENDS_SHOWN, preserve_keys: true);
    }

    public function searchFor(string $term): void
    {
        $this->tableSearch = $term;
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->searchableContent())
            ->columns([
                TextColumn::make('resource_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => $this->typeLabel($state)),
                TextColumn::make('body')
                    ->label('Content')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('body', 'like', '%'.trim($search).'%'))
                    ->formatStateUsing(fn (string $state): string => str($state)->squish()->limit(160)->toString())
                    ->wrap(),
                TextColumn::make('engagement_score')
                    ->label('Popularity')
                    ->icon('heroicon-m-fire')
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('resource_type')
                    ->label('Type')
                    ->options(fn (): array => DiscoveryIndex::query()
                        ->distinct()
                        ->pluck('resource_type')
                        ->mapWithKeys(fn (string $type): array => [$type => $this->typeLabel($type)])
                        ->sort()
                        ->all()),
            ])
            ->searchPlaceholder('Search posts, clubs and events')
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('engagement_score')->orderByDesc('published_at'))
            ->emptyStateHeading(fn (): string => filled($this->tableSearch) ? 'Nothing found' : 'Nothing to discover yet')
            ->emptyStateDescription(fn (): ?string => filled($this->tableSearch) ? 'Try another word, or one of the trending topics.' : null)
            ->emptyStateIcon('heroicon-o-magnifying-glass')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    /**
     * SearchDiscovery's visibility rule (public, or mine) as a query the table
     * can search, filter and paginate. SearchDiscovery itself only answers a
     * single capped search, so it is called to authorize the viewer.
     */
    private function searchableContent(): Builder
    {
        app(SearchDiscovery::class)->handle($this->viewer(), '');

        return DiscoveryIndex::query()->where(fn (Builder $query): Builder => $query
            ->where('visibility', 'public')
            ->orWhere('owner_profile_id', $this->viewer()->getKey()));
    }

    private function typeLabel(string $type): string
    {
        return str(class_basename(Relation::getMorphedModel($type) ?? $type))->headline()->toString();
    }
}
