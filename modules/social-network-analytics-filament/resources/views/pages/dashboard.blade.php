<x-filament-panels::page>
    @php($metrics = $this->metrics())

    @if (count($metrics) > 1)
        <div style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;">
            <span style="font-size: .875rem; color: var(--gray-500);">Metric</span>
            @foreach ($metrics as $name)
                <x-filament::badge
                    tag="button"
                    type="button"
                    :color="$this->metric === $name ? 'primary' : 'gray'"
                    wire:click="selectMetric(@js($name))"
                    style="cursor: pointer;"
                >
                    {{ str($name)->headline() }}
                </x-filament::badge>
            @endforeach
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
