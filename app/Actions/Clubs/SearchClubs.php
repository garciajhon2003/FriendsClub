<?php

namespace App\Actions\Clubs;

use App\Enums\ClubModality;
use App\Models\Hobby;
use Illuminate\Support\Collection;
use Liberu\SocialNetwork\Communities\Models\Community;

class SearchClubs
{
    private const EARTH_RADIUS_KM = 6371;

    public function handle(
        ?string $hobby = null,
        ?ClubModality $modality = null,
        ?int $day = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        ?string $search = null,
        bool $onlyWithFreeSpots = false,
        int $limit = 24,
    ): Collection {
        $nearby = $latitude !== null && $longitude !== null && $radiusKm !== null;

        $query = Community::query()
            ->with('hobby')
            ->withCount(['memberships as members_count' => fn($q) => $q->where('status', 'active')])
            ->where('visibility', 'public');

        if ($hobby !== null) {
            $query->where('hobby_id', Hobby::where('slug', $hobby)->value('id'));
        }

        if ($modality !== null) {
            $query->where('modality', $modality->value);
        }

        if ($day !== null) {
            $query->where('meeting_day', $day);
        }

        if ($search !== null && $search !== '') {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if ($nearby) {
            // Primer filtro, rápido y en SQL: un "cuadrado" alrededor del punto.
            $latDelta = $radiusKm / 111.045;
            $lngDelta = $radiusKm / (111.045 * cos(deg2rad($latitude)));

            $query->where('modality', ClubModality::InPerson->value)
                ->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta])
                ->whereBetween('longitude', [$longitude - $lngDelta, $longitude + $lngDelta]);
        }

        return $query->get()
            ->each(function (Community $club) use ($nearby, $latitude, $longitude) {
                $club->free_spots = $club->max_members === null
                    ? null
                    : max(0, (int) $club->max_members - (int) $club->members_count);

                $club->distance_km = $nearby
                    ? round(self::distanceKm($latitude, $longitude, (float) $club->latitude, (float) $club->longitude), 1)
                    : null;
            })
            // Segundo filtro, exacto: la distancia real (el cuadrado tiene esquinas de más).
            ->when($nearby, fn($clubs) => $clubs->filter(fn($club) => $club->distance_km <= $radiusKm))
            ->when($onlyWithFreeSpots, fn($clubs) => $clubs->filter(fn($club) => $club->free_spots !== 0))
            ->sortBy($nearby ? 'distance_km' : 'name')
            ->take($limit)
            ->values();
    }

    /** Distancia en km entre dos puntos de la Tierra (fórmula de Haversine). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
