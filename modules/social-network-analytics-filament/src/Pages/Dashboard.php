<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Analytics\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Liberu\SocialNetwork\Analytics\Actions\GetMetrics;
use Liberu\SocialNetwork\Analytics\Filament\Widgets\MetricSummary;
use Liberu\SocialNetwork\Analytics\Filament\Widgets\MetricTrend;
use Liberu\SocialNetwork\Analytics\Models\MetricSnapshot;
use Livewire\Attributes\Url;

/**
 * Network analytics for the people allowed to see them: pick a metric, read
 * the headline numbers, its trend and every reportable period. Only snapshots
 * with a large enough cohort are shown (GetMetrics' privacy rule).
 */
final class Dashboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'social-network-analytics-filament::pages.dashboard';

    /** Not "dashboard": that route name belongs to the panel's home page. */
    protected static ?string $slug = 'analytics';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'Analytics';

    protected static ?string $navigationLabel = 'Analytics';

    #[Url]
    public ?string $metric = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && Gate::forUser($user)->allows('social-network.analytics.view', ['metrics']);
    }

    public function mount(): void
    {
        $metrics = $this->metrics();

        if (! in_array($this->metric, $metrics, true)) {
            $this->metric = $metrics[0] ?? null;
        }
    }

    /** @return list<string> metrics that have at least one reportable period */
    public function metrics(): array
    {
        return MetricSnapshot::query()
            ->where('cohort_size', '>=', (int) config('social-network-analytics.minimum_cohort_size'))
            ->distinct()
            ->orderBy('metric')
            ->pluck('metric')
            ->all();
    }

    public function selectMetric(string $metric): void
    {
        abort_unless(in_array($metric, $this->metrics(), true), 404);

        $this->metric = $metric;
        $this->resetTable();
    }

    public function getWidgetData(): array
    {
        return ['metric' => $this->metric];
    }

    protected function getHeaderWidgets(): array
    {
        return $this->metric === null ? [] : [MetricSummary::class, MetricTrend::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Periods')
            ->query(fn (): Builder => $this->reportablePeriods())
            ->columns([
                TextColumn::make('period')
                    ->state(fn (MetricSnapshot $record): string => $record->period_start->format('j M Y').' – '.$record->period_end->format('j M Y')),
                TextColumn::make('value')
                    ->numeric(maxDecimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('cohort_size')
                    ->label('Cohort')
                    ->formatStateUsing(fn (int $state): string => Number::format($state))
                    ->sortable(),
            ])
            ->defaultSort('period_end', 'desc')
            ->emptyStateHeading('No analytics yet')
            ->emptyStateDescription('Metrics appear here once there are periods with enough people to report without identifying anyone.')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->paginated([10, 25, 50]);
    }

    /** GetMetrics' rule (authorized viewer, reportable cohorts) as a paginated query. */
    private function reportablePeriods(): Builder
    {
        if ($this->metric === null) {
            return MetricSnapshot::query()->whereRaw('1 = 0');
        }

        app(GetMetrics::class)->handle(auth()->user(), $this->metric, 1);

        return MetricSnapshot::query()
            ->where('metric', $this->metric)
            ->where('cohort_size', '>=', (int) config('social-network-analytics.minimum_cohort_size'));
    }
}
