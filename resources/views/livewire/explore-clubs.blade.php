<div>
    <h1>{{ __('Explore clubs') }}</h1>

    <div>
        <input type="search" wire:model.live.debounce.400ms="search"
            placeholder="{{ __('What would you like to explore?') }}">

        <select wire:model.live="city">
            <option value="">{{ __('All locations') }}</option>
            @foreach ($cities as $key => $place)
                <option value="{{ $key }}">{{ $place['name'] }}</option>
            @endforeach
        </select>

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
        @forelse ($clubs as $club)
            <li wire:key="{{ $club->id }}">
                <strong>{{ $club->name }}</strong>
                — {{ $club->hobby?->name }}
                — {{ \App\Enums\ClubModality::from($club->modality)->label() }}
                @if ($club->city)
                    — {{ $club->city }}
                @endif
                @if ($club->meeting_day)
                    — {{ $days[$club->meeting_day] }} {{ substr($club->meeting_time, 0, 5) }}
                @endif
                — {{ $club->members_count }}{{ $club->max_members ? '/' . $club->max_members : '' }}
                {{ __('members') }}
                @if ($club->free_spots !== null)
                    — {{ trans_choice(':count free spot|:count free spots', $club->free_spots) }}
                @endif
                @if ($club->distance_km !== null)
                    — {{ $club->distance_km }} km
                @endif
            </li>
        @empty
            <li>{{ __('No clubs match your filters.') }}</li>
        @endforelse
    </ul>
</div>
