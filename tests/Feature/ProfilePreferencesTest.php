<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Enums\CommunityModality;
use Liberu\SocialNetwork\Profiles\Models\Profile;

function preferencesProfile(): Profile
{
    config()->set('social-network-profiles.user_model', User::class);
    $user = User::factory()->create();

    return Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'handle' => 'prefs-'.strtolower(substr((string) $user->getKey(), 0, 8)),
    ]);
}

it('stores the city of a profile', function () {
    $profile = preferencesProfile();
    $profile->forceFill(['city' => 'Barcelona', 'country_code' => 'ES'])->save();

    expect($profile->fresh()->city)->toBe('Barcelona')
        ->and($profile->fresh()->country_code)->toBe('ES');
});

it('stores matching preferences with casts', function () {
    $profile = preferencesProfile();

    $profile->preferences()->create([
        'modality' => CommunityModality::InPerson,
        'max_distance_km' => 10,
        'available_days' => [4, 6],
        'time_slots' => ['evening'],
        'languages' => ['es'],
    ]);

    $prefs = $profile->fresh()->preferences;

    expect($prefs->modality)->toBe(CommunityModality::InPerson)
        ->and($prefs->available_days)->toBe([4, 6])
        ->and($prefs->languages)->toBe(['es'])
        ->and($prefs->profile->is($profile))->toBeTrue();
});

it('allows only one preferences row per profile', function () {
    $profile = preferencesProfile();
    $profile->preferences()->create(['max_distance_km' => 5]);

    expect(fn () => $profile->preferences()->create(['max_distance_km' => 20]))
        ->toThrow(QueryException::class);
});
