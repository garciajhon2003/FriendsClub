<?php

use App\Enums\ClubModality;
use App\Models\User;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Models\Profile;

function clubOwner(): Profile
{
    config()->set('social-network-profiles.user_model', User::class);
    $user = User::factory()->create();

    return Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'handle' => 'club-' . strtolower(substr((string) $user->getKey(), 0, 8)),
    ]);
}

function newClub(array $fields = []): Community
{
    $club = Community::query()->create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => clubOwner()->getKey(),
        'name' => 'Senderismo Juntos',
        'slug' => 'senderismo-' . Str::lower(Str::random(6)),
    ]);

    $club->forceFill($fields)->save();

    return $club->fresh();
}

it('defaults new clubs to online with no limits or schedule', function () {
    $club = newClub();

    expect($club->modality)->toBe('online')
        ->and($club->max_members)->toBeNull()
        ->and($club->meeting_day)->toBeNull();
});

it('stores an in-person club with location, capacity and schedule', function () {
    $club = newClub([
        'modality' => ClubModality::InPerson->value,
        'city' => 'Barcelona',
        'latitude' => 41.3873974,
        'longitude' => 2.1686090,
        'max_members' => 6,
        'meeting_day' => 6,
        'meeting_time' => '09:00:00',
        'timezone' => 'Europe/Madrid',
    ]);

    expect(ClubModality::from($club->modality))->toBe(ClubModality::InPerson)
        ->and($club->city)->toBe('Barcelona')
        ->and((float) $club->latitude)->toBe(41.3873974)
        ->and((int) $club->max_members)->toBe(6)
        ->and((int) $club->meeting_day)->toBe(6)
        ->and($club->meeting_time)->toBe('09:00:00');
});
