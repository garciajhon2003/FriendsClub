<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Profiles\Models\Profile;

it('denies unauthenticated social abilities and leaves other abilities untouched', function () {
    expect(Gate::forUser(null)->allows('social-network.profiles.view', [new Profile(['visibility' => 'public'])]))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewTelescope'))->toBeFalse();
});

it('allows profile owners and public profile reads while denying private reads', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owned = new Profile(['user_id' => $owner->getKey(), 'visibility' => 'private']);
    $public = new Profile(['user_id' => $other->getKey(), 'visibility' => 'public']);
    $private = new Profile(['user_id' => $other->getKey(), 'visibility' => 'private']);

    expect(Gate::forUser($owner)->allows('social-network.profiles.update', [$owned]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('social-network.profiles.view', [$public]))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('social-network.profiles.view', [$private]))->toBeFalse();
});

it('allows team members to access team-scoped social settings', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->getKey()]);

    expect(Gate::forUser($owner)->allows('social-network.social-core.view', [$team->getKey()]))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('social-network.social-core.view', [$team->getKey()]))->toBeFalse();
});

it('allows an allowlisted administrator to bypass social ability subjects', function () {
    $admin = User::factory()->create(['email' => 'social-admin@example.test']);
    config()->set('app.admin_emails', ['social-admin@example.test']);

    expect(Gate::forUser($admin)->allows('social-network.messaging.send', [new stdClass()]))->toBeTrue();
});

it('denies social abilities with unsupported subjects by default', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('social-network.messaging.send', [new stdClass()]))->toBeFalse();
});

it('does not let members moderate reports or verify profiles just by owning their profile', function () {
    $user = User::factory()->create();
    $profile = Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'handle' => 'plain-member',
    ]);

    expect(Gate::forUser($user)->allows('social-network.moderation.decide', [$profile]))->toBeFalse()
        ->and(Gate::forUser($user)->allows('social-network.profiles.verify', [$profile]))->toBeFalse()
        ->and(Gate::forUser($user)->allows('social-network.moderation.report', [$profile]))->toBeTrue();
});

it('still lets admins moderate reports', function () {
    config(['app.admin_emails' => ['mod@example.com']]);
    $admin = User::factory()->create(['email' => 'mod@example.com']);
    $profile = new Profile(['user_id' => $admin->getKey()]);

    expect(Gate::forUser($admin)->allows('social-network.moderation.decide', [$profile]))->toBeTrue();
});

it('lets people manage their own events and communities, but not other people\'s', function () {
    $person = function (): array {
        $user = User::factory()->create();

        return [$user, Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => $user->getKey(), 'handle' => Str::lower(Str::random(10))])];
    };
    [$ownerUser, $owner] = $person();
    [$strangerUser, $stranger] = $person();

    $event = Event::query()->create(['id' => (string) Str::uuid(), 'owner_profile_id' => $owner->id, 'title' => 'Ruta', 'starts_at' => now()->addDay(), 'state' => 'published', 'visibility' => 'public']);
    $club = Community::query()->create(['id' => (string) Str::uuid(), 'owner_profile_id' => $owner->id, 'name' => 'Club', 'slug' => Str::lower(Str::random(10)), 'visibility' => 'public']);

    foreach (['social-network.events.manage' => $event, 'social-network.communities.manage' => $club] as $ability => $subject) {
        expect(Gate::forUser($ownerUser)->allows($ability, [$owner, $subject]))->toBeTrue()
            ->and(Gate::forUser($strangerUser)->allows($ability, [$stranger, $subject]))->toBeFalse()
            ->and(Gate::forUser($strangerUser)->allows($ability, [$owner, $subject]))->toBeFalse();
    }
});
