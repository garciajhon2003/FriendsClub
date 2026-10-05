<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Feed\Filament\Pages\Entries;
use Liberu\SocialNetwork\Feed\Models\FeedEntry;
use Liberu\SocialNetwork\Moderation\Filament\Pages\Reports;
use Liberu\SocialNetwork\Moderation\Models\ModerationDecision;
use Liberu\SocialNetwork\Moderation\Models\ModerationReport;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Models\Publication;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.admin_emails' => ['moderator@example.com']]);

    $this->member = User::factory()->create(['name' => 'Member', 'onboarding_completed_at' => now()]);
    $this->memberProfile = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => $this->member->getKey(), 'handle' => 'member']);
    $this->moderator = User::factory()->create(['name' => 'Moderator', 'email' => 'moderator@example.com', 'onboarding_completed_at' => now()]);
    $this->author = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create(['name' => 'Author'])->getKey(), 'handle' => 'author']);

    $this->post = Publication::query()->create([
        'id' => (string) Str::uuid(),
        'author_profile_id' => $this->author->id,
        'kind' => 'post',
        'state' => 'published',
        'audience' => 'public',
        'body' => 'Compra seguidores baratos aquí',
        'published_at' => now()->subHour(),
    ]);

    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function reportBy(Profile $reporter, Publication $target, string $reason = 'spam', string $state = 'open'): ModerationReport
{
    return ModerationReport::query()->create([
        'id' => (string) Str::uuid(),
        'reporter_profile_id' => $reporter->id,
        'target_type' => $target->getMorphClass(),
        'target_id' => $target->id,
        'reason' => $reason,
        'state' => $state,
    ]);
}

it('lets a member report a post from the feed', function () {
    $entry = FeedEntry::query()->create([
        'id' => (string) Str::uuid(),
        'viewer_profile_id' => $this->memberProfile->id,
        'item_type' => $this->post->getMorphClass(),
        'item_id' => $this->post->id,
        'rank' => 1,
        'visible_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->member);

    Livewire::test(Entries::class)
        ->callAction(TestAction::make('report')->arguments(['entry' => $entry->id]), ['reason' => 'spam', 'details' => 'Es publicidad'])
        ->assertHasNoActionErrors()
        ->assertNotified('Thanks, the moderators will review it');

    $report = ModerationReport::query()->firstOrFail();

    expect($report->reporter_profile_id)->toBe($this->memberProfile->id)
        ->and($report->target_id)->toBe($this->post->id)
        ->and($report->reason)->toBe('spam')
        ->and($report->details)->toBe('Es publicidad');
});

it('shows members only their own reports, without a decide button', function () {
    $mine = reportBy($this->memberProfile, $this->post);
    $other = reportBy($this->author, $this->post, 'hate');

    $this->actingAs($this->member);

    Livewire::test(Reports::class)
        ->assertSee('My reports')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableActionHidden('decide', $mine);

    expect(Reports::getNavigationBadge())->toBeNull();
});

it('shows moderators the open queue with a badge', function () {
    $open = reportBy($this->memberProfile, $this->post);
    $resolved = reportBy($this->author, $this->post, 'hate', 'resolved');

    $this->actingAs($this->moderator);

    Livewire::test(Reports::class)
        ->assertSee(['Moderation queue', 'Compra seguidores baratos', 'Member'])
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$resolved]);

    expect(Reports::getNavigationBadge())->toBe('1');
});

it('lets a moderator decide a report', function () {
    $report = reportBy($this->memberProfile, $this->post);

    $this->actingAs($this->moderator);

    Livewire::test(Reports::class)
        ->callTableAction('decide', $report, ['action' => 'remove', 'reason' => 'Publicidad engañosa'])
        ->assertHasNoTableActionErrors();

    $decision = ModerationDecision::query()->where('report_id', $report->id)->firstOrFail();

    expect($report->fresh()->state)->toBe('resolved')
        ->and($decision->action)->toBe('remove')
        ->and($decision->reason)->toBe('Publicidad engañosa');
});

it('lets the reporter see the outcome without the moderator name', function () {
    $report = reportBy($this->memberProfile, $this->post, state: 'resolved');
    ModerationDecision::query()->create([
        'report_id' => $report->id,
        'actor_profile_id' => $this->author->id,
        'action' => 'warn',
        'reason' => 'Primer aviso',
    ]);

    $this->actingAs($this->member);

    Livewire::test(Reports::class)
        ->assertSee('Warn the author')
        ->mountTableAction('view', $report)
        ->assertMountedActionModalSee(['Primer aviso', 'A moderator'])
        ->assertMountedActionModalDontSee('Author');
});
