<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Analytics\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Liberu\SocialNetwork\Analytics\Actions\GetMetrics;
use Liberu\SocialNetwork\Analytics\Models\MetricSnapshot;
use Livewire\Attributes\Reactive;

/**
 * One metric over time: a single series, so a line in the panel's primary
 * colour with no legend (the heading names it) and a hover tooltip per period.
 */
final class MetricTrend extends ChartWidget
{
    /** Set by the analytics page; the widget re-renders when it changes. */
    #[Reactive]
    public ?string $metric = null;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public function getHeading(): ?string
    {
        return $this->metric === null ? 'Trend' : str($this->metric)->headline()->append(' over time')->toString();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $snapshots = $this->metric === null
            ? collect()
            : app(GetMetrics::class)->handle(auth()->user(), $this->metric, 30)->sortBy('period_end')->values();

        return [
            'datasets' => [[
                'label' => str((string) $this->metric)->headline()->toString(),
                'data' => $snapshots->map(fn (MetricSnapshot $snapshot): float => (float) $snapshot->value)->all(),
                'borderWidth' => 2,
                'pointRadius' => 4,
                'pointHoverRadius' => 6,
                'tension' => 0,
                'fill' => false,
            ]],
            'labels' => $snapshots->map(fn (MetricSnapshot $snapshot): string => $snapshot->period_end->format('j M'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => ['mode' => 'index', 'intersect' => false],
            ],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'y' => ['beginAtZero' => true, 'grid' => ['drawTicks' => false]],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }
}
