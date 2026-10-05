<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Analytics\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Liberu\SocialNetwork\Analytics\Actions\GetMetrics;
use Liberu\SocialNetwork\Analytics\Models\MetricSnapshot;
use Livewire\Attributes\Reactive;

/** Headline numbers for one metric: latest period, change, and cohort size. */
final class MetricSummary extends StatsOverviewWidget
{
    /** Set by the analytics page; the widget re-renders when it changes. */
    #[Reactive]
    public ?string $metric = null;

    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        if ($this->metric === null) {
            return [];
        }

        $snapshots = app(GetMetrics::class)->handle(auth()->user(), $this->metric, 30);
        $latest = $snapshots->first();
        if (! $latest instanceof MetricSnapshot) {
            return [Stat::make('Latest period', '—')->description('No reportable periods yet')];
        }

        $previous = $snapshots->skip(1)->first();
        $change = $previous instanceof MetricSnapshot ? (float) $latest->value - (float) $previous->value : null;

        return [
            Stat::make('Latest period', $this->format((float) $latest->value))
                ->description($latest->period_start->format('j M').' – '.$latest->period_end->format('j M Y')),
            Stat::make('Change', $change === null ? '—' : ($change >= 0 ? '+' : '−').$this->format(abs($change)))
                ->description($change === null ? 'Needs two periods' : 'vs. the period before')
                ->descriptionIcon($change === null ? null : ($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'))
                ->color($change === null ? 'gray' : ($change >= 0 ? 'success' : 'danger')),
            Stat::make('Cohort', Number::format((int) $latest->cohort_size))
                ->description('people behind the latest figure'),
        ];
    }

    private function format(float $value): string
    {
        return Number::format($value, maxPrecision: 2);
    }
}
