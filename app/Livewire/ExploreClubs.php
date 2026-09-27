<?php

namespace App\Livewire;

use App\Actions\Clubs\SearchClubs;
use App\Enums\ClubModality;
use App\Models\Hobby;
use Livewire\Attributes\Url;
use Livewire\Component;

class ExploreClubs extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $hobby = '';

    #[Url]
    public string $modality = '';

    #[Url]
    public string $day = '';

    #[Url]
    public string $city = '';

    public function render(SearchClubs $searchClubs)
    {
        $cities = config('friendsclub.cities');
        $place = $cities[$this->city] ?? null;

        $clubs = $searchClubs->handle(
            hobby: $this->hobby ?: null,
            modality: ClubModality::tryFrom($this->modality),
            day: $this->day !== '' ? (int) $this->day : null,
            latitude: $place['lat'] ?? null,
            longitude: $place['lng'] ?? null,
            radiusKm: $place ? config('friendsclub.search_radius_km') : null,
            search: $this->search ?: null,
        );

        return view('livewire.explore-clubs', [
            'clubs' => $clubs,
            'hobbies' => Hobby::active()->get(),
            'modalities' => ClubModality::cases(),
            'cities' => $cities,
            'days' => self::dayNames(),
        ]);
    }

    /** @return array<int, string> 1 = lunes … 7 = domingo */
    public static function dayNames(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
            7 => __('Sunday'),
        ];
    }
}
