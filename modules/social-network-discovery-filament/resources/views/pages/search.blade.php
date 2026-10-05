<x-filament-panels::page>
    @php($trends = $this->trends())

    @if ($trends !== [])
        <x-filament::section heading="Trending" icon="heroicon-o-arrow-trending-up" compact>
            <div style="display: flex; flex-wrap: wrap; gap: .5rem;">
                @foreach ($trends as $term => $count)
                    <x-filament::badge
                        tag="button"
                        type="button"
                        :color="$this->tableSearch === (string) $term ? 'primary' : 'gray'"
                        wire:click="searchFor(@js((string) $term))"
                        style="cursor: pointer;"
                    >
                        #{{ $term }}
                    </x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
