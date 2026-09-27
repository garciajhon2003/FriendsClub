<?php

use App\Models\Hobby;
use App\Models\User;
use Database\Seeders\HobbySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Models\Profile;

use function Pest\Laravel\seed;

function hobbyProfile(): Profile
{
    config()->set('social-network-profiles.user_model', User::class);
    $user = User::factory()->create();

    return Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'handle' => 'hobby-' . strtolower(substr((string) $user->getKey(), 0, 8)),
    ]);
}

function hobbyCommunity(Profile $owner, Hobby $hobby): Community
{
    $community = Community::query()->create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => $owner->getKey(),
        'name' => 'Amantes de la Repostería',
        'slug' => 'amantes-reposteria-' . Str::lower(Str::random(6)),
    ]);

    $community->hobby()->associate($hobby)->save();

    return $community;
}

beforeEach(fn() => seed(HobbySeeder::class));

it('attaches hobbies with a level to a profile', function () {
    $profile = hobbyProfile();
    $reposteria = Hobby::where('slug', 'reposteria')->first();

    $profile->hobbies()->attach($reposteria, ['level' => 'avanzado']);

    expect($profile->hobbies)->toHaveCount(1)
        ->and($profile->hobbies->first()->pivot->level)->toBe('avanzado')
        ->and($reposteria->profiles)->toHaveCount(1);
});

it('links a club to its hobby in both directions', function () {
    $hobby = Hobby::where('slug', 'fotografia')->first();
    $club = hobbyCommunity(hobbyProfile(), $hobby);

    expect($club->fresh()->hobby->slug)->toBe('fotografia')
        ->and($hobby->communities)->toHaveCount(1);
});

it('does not allow deleting a hobby that clubs are using', function () {
    $hobby = Hobby::where('slug', 'lectura')->first();
    hobbyCommunity(hobbyProfile(), $hobby);

    expect(fn() => $hobby->delete())->toThrow(QueryException::class);
});
