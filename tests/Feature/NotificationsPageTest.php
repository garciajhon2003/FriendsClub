<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Notifications\Actions\CreateNotification;
use Liberu\SocialNetwork\Notifications\Filament\Pages\Notifications;
use Liberu\SocialNetwork\Notifications\Models\NotificationPreference;
use Liberu\SocialNetwork\Notifications\Models\SocialNotification;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->profile = Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->user->getKey(),
        'handle' => 'tester',
    ]);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function notifyTester(Profile $profile, string $kind = 'social.follow', string $message = 'Laura empezó a seguirte'): SocialNotification
{
    return app(CreateNotification::class)->handle((string) $profile->getKey(), $kind, ['message' => $message]);
}

it('renders the notifications page', function () {
    $this->get(route('filament.app.pages.notifications'))->assertOk();
});

it('lists my notifications with their message, and nobody else\'s', function () {
    $mine = notifyTester($this->profile);
    $other = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->getKey(), 'handle' => 'other']);
    $theirs = notifyTester($other, message: 'Para otra persona');

    Livewire::test(Notifications::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSee('Laura empezó a seguirte');
});

it('falls back to a readable type when there is no message', function () {
    app(CreateNotification::class)->handle((string) $this->profile->getKey(), 'social.event.reminder');

    Livewire::test(Notifications::class)->assertSee('Event reminder');
});

it('marks one notification as read and updates the menu badge', function () {
    $notification = notifyTester($this->profile);
    notifyTester($this->profile, 'social.comment', 'Javier comentó');

    expect(Notifications::getNavigationBadge())->toBe('2');

    Livewire::test(Notifications::class)->callTableAction('markRead', $notification);

    expect($notification->fresh())->state->toBe('read')->read_at->not->toBeNull()
        ->and(Notifications::getNavigationBadge())->toBe('1');
});

it('marks everything as read', function () {
    notifyTester($this->profile);
    notifyTester($this->profile, 'social.comment', 'Javier comentó');

    Livewire::test(Notifications::class)
        ->callAction('markAllRead')
        ->assertNotified('2 marked as read');

    expect(Notifications::getNavigationBadge())->toBeNull();
});

it('dismisses notifications and hides them by default', function () {
    $notification = notifyTester($this->profile);

    Livewire::test(Notifications::class)
        ->callTableAction('dismiss', $notification)
        ->assertCanNotSeeTableRecords([$notification]);

    expect($notification->fresh()->state)->toBe('dismissed');
});

it('marks and dismisses in bulk', function () {
    $first = notifyTester($this->profile);
    $second = notifyTester($this->profile, 'social.comment', 'Javier comentó');

    Livewire::test(Notifications::class)
        ->callTableBulkAction('markSelectedRead', [$first, $second]);

    expect($first->fresh()->state)->toBe('read')->and($second->fresh()->state)->toBe('read');

    Livewire::test(Notifications::class)
        ->callTableBulkAction('dismissSelected', [$first]);

    expect($first->fresh()->state)->toBe('dismissed')->and($second->fresh()->state)->toBe('read');
});

it('saves the notification preferences', function () {
    Livewire::test(Notifications::class)
        ->callAction('preferences', [
            'channels' => ['in_app', 'email'],
            'quiet_enabled' => true,
            'quiet_from' => '22:00',
            'quiet_to' => '08:00',
            'digest' => 'daily',
        ])
        ->assertHasNoActionErrors();

    $preferences = NotificationPreference::query()->where('profile_id', $this->profile->id)->firstOrFail();

    expect($preferences->channels)->toBe(['in_app', 'email'])
        ->and($preferences->quiet_hours)->toBe(['from' => '22:00', 'to' => '08:00'])
        ->and($preferences->digest)->toBe(['frequency' => 'daily']);

    Livewire::test(Notifications::class)
        ->mountAction('preferences')
        ->assertSchemaStateSet(['channels' => ['in_app', 'email'], 'quiet_enabled' => true, 'digest' => 'daily']);
});
