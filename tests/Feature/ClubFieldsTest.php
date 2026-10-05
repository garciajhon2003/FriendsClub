<?php

use App\Models\User;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Actions\CreateCommunity;
use Liberu\SocialNetwork\Communities\Actions\JoinCommunity;
use Liberu\SocialNetwork\Communities\Enums\CommunityModality;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Models\Profile;

function clubProfile(): Profile
{
    $user = User::factory()->create();

    return Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->getKey(),
        'handle' => 'club-'.strtolower(substr((string) $user->getKey(), 0, 8)).Str::lower(Str::random(4)),
    ]);
}

/** @param array<string, mixed> $attributes */
function createClub(Profile $owner, array $attributes = []): Community
{
    test()->actingAs(User::find($owner->user_id));

    return app(CreateCommunity::class)->handle($owner, ['name' => 'Senderismo Juntos', ...$attributes])->fresh();
}

it('defaults new clubs to online with no limits or schedule', function () {
    $club = createClub(clubProfile());

    expect($club->modality)->toBe('online')
        ->and($club->city)->toBeNull()
        ->and($club->max_members)->toBeNull()
        ->and($club->meeting_day)->toBeNull();
});

it('stores an in-person club with location, capacity and schedule', function () {
    $club = createClub(clubProfile(), [
        'modality' => CommunityModality::InPerson->value,
        'city' => 'Barcelona',
        'latitude' => 41.3873974,
        'longitude' => 2.1686090,
        'max_members' => 6,
        'meeting_day' => 6,
        'meeting_time' => '09:00',
        'timezone' => 'Europe/Madrid',
    ]);

    expect(CommunityModality::from($club->modality))->toBe(CommunityModality::InPerson)
        ->and($club->city)->toBe('Barcelona')
        ->and($club->latitude)->toBe(41.3873974)
        ->and($club->max_members)->toBe(6)
        ->and($club->meeting_day)->toBe(6)
        ->and(substr((string) $club->meeting_time, 0, 5))->toBe('09:00')
        ->and($club->timezone)->toBe('Europe/Madrid');
});

it('drops the location of an online club', function () {
    $club = createClub(clubProfile(), ['modality' => 'online', 'city' => 'Madrid', 'latitude' => 40.4, 'longitude' => -3.7]);

    expect($club->city)->toBeNull()->and($club->latitude)->toBeNull();
});

it('refuses invalid meeting details', function (array $attributes, string $message) {
    expect(fn () => createClub(clubProfile(), $attributes))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown modality' => [['modality' => 'teleport'], 'The community modality is not supported.'],
    'in person without a city' => [['modality' => 'in_person'], 'In-person communities need a city.'],
    'half a coordinate' => [['modality' => 'in_person', 'city' => 'Madrid', 'latitude' => 40.4], 'Coordinates need a valid latitude and longitude.'],
    'one spot' => [['max_members' => 1], 'Spots must be a whole number between 2 and 500.'],
    'fractional spots' => [['max_members' => 4.5], 'Spots must be a whole number between 2 and 500.'],
    'day eight' => [['meeting_day' => 8], 'The meeting day must be between 1 (Monday) and 7 (Sunday).'],
    'bad time' => [['meeting_time' => '25:00'], 'The meeting time must be HH:MM.'],
]);

it('refuses a new member once the club is full, whatever the entry point', function () {
    $club = createClub(clubProfile(), ['max_members' => 2]);

    $second = clubProfile();
    test()->actingAs(User::find($second->user_id));
    app(JoinCommunity::class)->handle($second, $club);

    $third = clubProfile();
    test()->actingAs(User::find($third->user_id));

    expect($club->isFull())->toBeTrue()
        ->and(fn () => app(JoinCommunity::class)->handle($third, $club))
        ->toThrow(InvalidArgumentException::class, 'This community is full.');
});

it('lets an existing member join again when the club is full', function () {
    $owner = clubProfile();
    $club = createClub($owner, ['max_members' => 2]);
    $member = clubProfile();
    test()->actingAs(User::find($member->user_id));
    app(JoinCommunity::class)->handle($member, $club);

    expect(app(JoinCommunity::class)->handle($member, $club)->status)->toBe('active');
});
