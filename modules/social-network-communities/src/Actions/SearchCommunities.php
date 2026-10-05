<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Liberu\SocialNetwork\Communities\Enums\CommunityModality;
use Liberu\SocialNetwork\Communities\Models\Community;

/**
 * Public directory search: by name, modality, meeting day, distance to a point
 * and free spots. Each result carries `members_count`, `free_spots` (null when
 * unlimited) and `distance_km` (null unless searching near a point).
 */
final class SearchCommunities
{
    private const int EARTH_RADIUS_KM = 6371;

    private const float KM_PER_DEGREE = 111.045;

    /**
     * @param  (Closure(Builder<Community>): mixed)|null  $constrain  extra constraints a caller adds, e.g. a composition's own columns
     * @return Collection<int, Community>
     */
    public function handle(
        ?CommunityModality $modality = null,
        ?int $day = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        ?string $search = null,
        bool $onlyWithFreeSpots = false,
        int $limit = 24,
        ?Closure $constrain = null,
    ): Collection {
        $nearby = $latitude !== null && $longitude !== null && $radiusKm !== null;

        $query = Community::query()
            ->withCount(['memberships as members_count' => fn (Builder $memberships): Builder => $memberships->where('status', 'active')])
            ->where('visibility', 'public')
            ->when($modality !== null, fn (Builder $query): Builder => $query->where('modality', $modality?->value))
            ->when($day !== null, fn (Builder $query): Builder => $query->where('meeting_day', $day))
            ->when(filled($search), fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'));

        if ($nearby) {
            // A fast bounding box in SQL first; the exact distance filter runs below.
            $latDelta = $radiusKm / self::KM_PER_DEGREE;
            $lngDelta = $radiusKm / (self::KM_PER_DEGREE * cos(deg2rad($latitude)));

            $query->where('modality', CommunityModality::InPerson->value)
                ->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta])
                ->whereBetween('longitude', [$longitude - $lngDelta, $longitude + $lngDelta]);
        }

        if ($constrain !== null) {
            $constrain($query);
        }

        return $query->get()
            ->each(function (Community $community) use ($nearby, $latitude, $longitude): void {
                $community->free_spots = $community->max_members === null
                    ? null
                    : max(0, $community->max_members - (int) $community->members_count);

                $community->distance_km = $nearby
                    ? round(self::distanceKm((float) $latitude, (float) $longitude, (float) $community->latitude, (float) $community->longitude), 1)
                    : null;
            })
            ->when($nearby, fn (Collection $communities): Collection => $communities->filter(fn (Community $community): bool => $community->distance_km <= $radiusKm))
            ->when($onlyWithFreeSpots, fn (Collection $communities): Collection => $communities->filter(fn (Community $community): bool => $community->free_spots !== 0))
            ->sortBy($nearby ? 'distance_km' : 'name')
            ->take($limit)
            ->values();
    }

    /** Great-circle distance in km between two points (haversine formula). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
