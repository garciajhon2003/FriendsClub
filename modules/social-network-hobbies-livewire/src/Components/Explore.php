<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Livewire\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Liberu\SocialNetwork\Communities\Actions\SearchCommunities;
use Liberu\SocialNetwork\Communities\Enums\CommunityModality;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Public directory: find a community by hobby, modality, meeting day and place.
 * The filters live in the URL, so a search can be shared as a link.
 */
final class Explore extends Component
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

    public function render(SearchCommunities $searchCommunities): View
    {
        /** @var array<string, array{name: string, lat: float, lng: float}> $cities */
        $cities = (array) config('social-network-hobbies-livewire.cities');
        $place = $cities[$this->city] ?? null;
        $hobbyId = $this->hobby === '' ? null : Hobby::query()->where('slug', $this->hobby)->value('id');

        $communities = $searchCommunities->handle(
            modality: CommunityModality::tryFrom($this->modality),
            day: $this->day === '' ? null : (int) $this->day,
            latitude: $place['lat'] ?? null,
            longitude: $place['lng'] ?? null,
            radiusKm: $place === null ? null : (int) config('social-network-hobbies-livewire.search_radius_km'),
            search: $this->search === '' ? null : $this->search,
            constrain: fn (Builder $query): Builder => $query
                ->with('hobby')
                // An unknown hobby matches nothing, rather than the communities without one.
                ->when($this->hobby !== '', fn (Builder $query): Builder => $query->whereIn('hobby_id', array_filter([$hobbyId]))),
        );

        return view('social-network-hobbies-livewire::livewire.explore', [
            'communities' => $communities,
            'hobbies' => Hobby::query()->active()->get(),
            'modalities' => CommunityModality::cases(),
            'cities' => $cities,
            'days' => self::dayNames(),
        ]);
    }

    /** @return array<int, string> 1 = Monday … 7 = Sunday */
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
