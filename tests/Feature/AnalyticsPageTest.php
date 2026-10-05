<?php

use App\Models\User;
use Filament\Facades\Filament;
use Liberu\SocialNetwork\Analytics\Filament\Pages\Dashboard;
use Liberu\SocialNetwork\Analytics\Filament\Widgets\MetricSummary;
use Liberu\SocialNetwork\Analytics\Filament\Widgets\MetricTrend;
use Liberu\SocialNetwork\Analytics\Models\MetricSnapshot;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.admin_emails' => ['analyst@example.com']]);

    $this->admin = User::factory()->create(['email' => 'analyst@example.com', 'onboarding_completed_at' => now()]);
    $this->member = User::factory()->create(['onboarding_completed_at' => now()]);

    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function snapshot(string $metric, int $weeksAgo, float $value, int $cohort = 20): MetricSnapshot
{
    $end = now()->startOfDay()->subWeeks($weeksAgo);

    return MetricSnapshot::query()->create([
        'metric' => $metric,
        'period_start' => $end->copy()->subWeek(),
        'period_end' => $end,
        'cohort_size' => $cohort,
        'value' => $value,
    ]);
}

it('has its own address instead of taking over the panel home', function () {
    expect(Dashboard::getUrl())->toEndWith('/app/analytics')
        ->and(route('filament.app.pages.dashboard'))->toEndWith('/app');
});

it('is only for people allowed to see analytics', function () {
    $this->actingAs($this->member)->get(Dashboard::getUrl())->assertForbidden();
    expect(Dashboard::canAccess())->toBeFalse();

    $this->actingAs($this->admin)->get(Dashboard::getUrl())->assertOk()->assertSee('Analytics');
});

it('shows the periods of a metric, hiding cohorts too small to report', function () {
    $recent = snapshot('growth', 0, 12);
    $older = snapshot('growth', 1, 8);
    $tooSmall = snapshot('growth', 2, 3, cohort: 4);

    $this->actingAs($this->admin);

    Livewire::test(Dashboard::class)
        ->assertSet('metric', 'growth')
        ->assertCanSeeTableRecords([$recent, $older])
        ->assertCanNotSeeTableRecords([$tooSmall]);
});

it('switches between metrics', function () {
    snapshot('growth', 0, 12);
    $retention = snapshot('retention', 0, 0.75);

    $this->actingAs($this->admin);

    Livewire::test(Dashboard::class)
        ->assertSee(['Growth', 'Retention'])
        ->call('selectMetric', 'retention')
        ->assertSet('metric', 'retention')
        ->assertCanSeeTableRecords([$retention]);
});

it('summarises the latest period and its change', function () {
    snapshot('growth', 0, 12);
    snapshot('growth', 1, 8);

    $this->actingAs($this->admin);

    Livewire::test(MetricSummary::class, ['metric' => 'growth'])
        ->assertSee(['Latest period', '12', '+4', 'Cohort', '20']);
});

it('charts the metric oldest first', function () {
    snapshot('growth', 0, 12);
    snapshot('growth', 1, 8);

    $this->actingAs($this->admin);

    $widget = Livewire::test(MetricTrend::class, ['metric' => 'growth'])->instance();
    $data = (new ReflectionMethod($widget, 'getData'))->invoke($widget);

    expect($data['datasets'][0]['data'])->toBe([8.0, 12.0])
        ->and($data['labels'])->toHaveCount(2);
});

it('says so when there is nothing to report yet', function () {
    $this->actingAs($this->admin);

    Livewire::test(Dashboard::class)
        ->assertSet('metric', null)
        ->assertSee('No analytics yet');
});
