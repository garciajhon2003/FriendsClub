<div>
    <h1>{{ __('Explore communities') }}</h1>

    <div>
        <input type="search" wire:model.live.debounce.400ms="search"
            placeholder="{{ __('What would you like to explore?') }}">

        @if ($cities !== [])
            <select wire:model.live="city">
                <option value="">{{ __('All locations') }}</option>
                @foreach ($cities as $key => $place)
                    <option value="{{ $key }}">{{ $place['name'] }}</option>
                @endforeach
            </select>
        @endif

        <select wire:model.live="hobby">
            <option value="">{{ __('All hobbies') }}</option>
            @foreach ($hobbies as $item)
                <option value="{{ $item->slug }}">{{ $item->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="modality">
            <option value="">{{ __('All') }}</option>
            @foreach ($modalities as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>

        <select wire:model.live="day">
            <option value="">{{ __('Any day') }}</option>
            @foreach ($days as $number => $name)
                <option value="{{ $number }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <ul>
        @forelse ($communities as $community)
            <li wire:key="{{ $community->id }}">
                <strong>{{ $community->name }}</strong>
                — {{ $community->hobby?->name }}
                — {{ \Liberu\SocialNetwork\Communities\Enums\CommunityModality::from($community->modality)->label() }}
                @if ($community->city)
                    — {{ $community->city }}
                @endif
                @if ($community->meeting_day)
                    — {{ $days[$community->meeting_day] }} {{ substr((string) $community->meeting_time, 0, 5) }}
                @endif
                — {{ $community->members_count }}{{ $community->max_members ? '/'.$community->max_members : '' }}
                {{ __('members') }}
                @if ($community->free_spots !== null)
                    — {{ trans_choice(':count free spot|:count free spots', $community->free_spots) }}
                @endif
                @if ($community->distance_km !== null)
                    — {{ $community->distance_km }} km
                @endif
            </li>
        @empty
            <li>{{ __('No communities match your filters.') }}</li>
        @endforelse
    </ul>
</div>
