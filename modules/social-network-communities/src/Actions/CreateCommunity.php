<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Liberu\SocialNetwork\Communities\Contracts\CommunityAuthorizer;
use Liberu\SocialNetwork\Communities\Enums\CommunityModality;
use Liberu\SocialNetwork\Communities\Events\CommunityCreated;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Profiles\Models\Profile;

final readonly class CreateCommunity
{
    public const int MIN_MEMBERS = 2;

    public const int MAX_MEMBERS = 500;

    public function __construct(private CommunityAuthorizer $authorizer, private Dispatcher $events) {}

    /** @param array<string,mixed> $attributes */
    public function handle(Profile $owner, array $attributes): Community
    {
        $this->authorizer->create($owner);
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Community names are required.');
        }
        $meeting = $this->meetingAttributes($attributes);
        $community = DB::transaction(function () use ($owner, $attributes, $name, $meeting): Community {
            $community = Community::query()->create(['id' => (string) Str::uuid(), 'owner_profile_id' => $owner->getKey(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'description' => $attributes['description'] ?? null, 'visibility' => $attributes['visibility'] ?? 'public', 'rules' => $attributes['rules'] ?? [], ...$meeting]);
            Membership::query()->create(['community_id' => $community->getKey(), 'profile_id' => $owner->getKey(), 'role' => 'owner', 'status' => 'active']);

            return $community;
        });
        $this->events->dispatch(new CommunityCreated($community));

        return $community;
    }

    /**
     * Where, how many and when: an online community has no location, an in-person
     * one needs a city, and coordinates are optional but must come in pairs.
     *
     * @param  array<string,mixed>  $attributes
     * @return array{modality: string, city: ?string, latitude: ?float, longitude: ?float, max_members: ?int, meeting_day: ?int, meeting_time: ?string, timezone: string}
     */
    private function meetingAttributes(array $attributes): array
    {
        $modality = CommunityModality::tryFrom((string) ($attributes['modality'] ?? CommunityModality::Online->value))
            ?? throw new InvalidArgumentException('The community modality is not supported.');
        $inPerson = $modality === CommunityModality::InPerson;

        $city = $inPerson ? trim((string) ($attributes['city'] ?? '')) : '';
        if ($inPerson && $city === '') {
            throw new InvalidArgumentException('In-person communities need a city.');
        }

        $latitude = $inPerson ? $this->nullableNumber($attributes['latitude'] ?? null) : null;
        $longitude = $inPerson ? $this->nullableNumber($attributes['longitude'] ?? null) : null;
        if (($latitude === null) !== ($longitude === null) || ($latitude !== null && abs($latitude) > 90) || ($longitude !== null && abs($longitude) > 180)) {
            throw new InvalidArgumentException('Coordinates need a valid latitude and longitude.');
        }

        $maxMembers = $this->nullableNumber($attributes['max_members'] ?? null);
        if ($maxMembers !== null && ($maxMembers != (int) $maxMembers || $maxMembers < self::MIN_MEMBERS || $maxMembers > self::MAX_MEMBERS)) {
            throw new InvalidArgumentException('Spots must be a whole number between '.self::MIN_MEMBERS.' and '.self::MAX_MEMBERS.'.');
        }

        $meetingDay = $this->nullableNumber($attributes['meeting_day'] ?? null);
        if ($meetingDay !== null && ! in_array($meetingDay, [1, 2, 3, 4, 5, 6, 7])) {
            throw new InvalidArgumentException('The meeting day must be between 1 (Monday) and 7 (Sunday).');
        }

        $meetingTime = filled($attributes['meeting_time'] ?? null) ? (string) $attributes['meeting_time'] : null;
        if ($meetingTime !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $meetingTime) !== 1) {
            throw new InvalidArgumentException('The meeting time must be HH:MM.');
        }

        return [
            'modality' => $modality->value,
            'city' => $inPerson ? $city : null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'max_members' => $maxMembers === null ? null : (int) $maxMembers,
            'meeting_day' => $meetingDay === null ? null : (int) $meetingDay,
            'meeting_time' => $meetingTime,
            'timezone' => (string) ($attributes['timezone'] ?? config('app.timezone')),
        ];
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Expected a number.');
        }

        return (float) $value;
    }
}
