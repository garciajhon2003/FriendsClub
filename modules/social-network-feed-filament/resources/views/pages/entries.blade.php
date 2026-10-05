{{--
    Portada: tarjetas del feed. El panel no compila Tailwind propio, así que el
    diseño usa componentes de Filament y este CSS plano con sus variables de color.
--}}
<x-filament-panels::page>
    <style>
        .sn-feed { display: flex; flex-direction: column; gap: 1rem; max-width: 44rem; width: 100%; margin-inline: auto; }
        .sn-feed-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
        .sn-feed-author { font-weight: 600; color: var(--gray-950); }
        .dark .sn-feed-author { color: #fff; }
        .sn-feed-meta { font-size: .8125rem; color: var(--gray-500); display: flex; gap: .375rem; align-items: center; }
        .sn-feed-title { font-size: 1.0625rem; font-weight: 600; margin-top: .5rem; color: var(--gray-950); }
        .dark .sn-feed-title { color: #fff; }
        .sn-feed-body { margin-top: .375rem; white-space: pre-line; overflow-wrap: anywhere; color: var(--gray-700); }
        .dark .sn-feed-body { color: var(--gray-300); }
        .sn-feed-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; margin-top: .875rem; padding-top: .75rem; border-top: 1px solid var(--gray-100); }
        .dark .sn-feed-bar { border-color: var(--gray-800); }
        .sn-feed-reaction { display: inline-flex; align-items: center; gap: .3rem; padding: .125rem .625rem; border-radius: 9999px; font-size: .8125rem; border: 1px solid var(--gray-200); background: transparent; color: inherit; cursor: pointer; }
        .dark .sn-feed-reaction { border-color: var(--gray-700); }
        .sn-feed-reaction.is-mine { border-color: var(--primary-500); background: color-mix(in srgb, var(--primary-500) 12%, transparent); color: var(--primary-700); }
        .dark .sn-feed-reaction.is-mine { color: var(--primary-300); }
        .sn-feed-spacer { flex: 1; }
        .sn-feed-comments { margin-top: .75rem; display: flex; flex-direction: column; gap: .5rem; }
        .sn-feed-comment { font-size: .875rem; padding: .5rem .75rem; border-radius: .625rem; background: var(--gray-50); }
        .dark .sn-feed-comment { background: color-mix(in srgb, var(--gray-800) 60%, transparent); }
        .sn-feed-comment strong { color: var(--gray-950); }
        .dark .sn-feed-comment strong { color: #fff; }
        .sn-feed-more-comments { font-size: .8125rem; color: var(--gray-500); }
        .sn-feed-empty { text-align: center; padding: 3rem 1rem; color: var(--gray-500); }
        .sn-feed-load { display: flex; justify-content: center; }
    </style>

    <div class="sn-feed">
        @forelse ($this->cards() as $item)
            @php($card = $item['card'])
            <x-filament::section wire:key="feed-{{ $item['entry_id'] }}">
                <div class="sn-feed-head">
                    <div>
                        @if (filled($card['author']))
                            <div class="sn-feed-author">{{ $card['author'] }}</div>
                        @endif
                        <div class="sn-feed-meta">
                            <x-filament::badge size="sm" color="gray">{{ str($item['type_label'])->singular() }}</x-filament::badge>
                            @if ($card['published_at'])
                                <span title="{{ $card['published_at']->toDayDateTimeString() }}">{{ $card['published_at']->diffForHumans() }}</span>
                            @endif
                        </div>
                    </div>

                    <x-filament::icon-button
                        icon="heroicon-m-eye-slash"
                        color="gray"
                        label="Hide from my feed"
                        tooltip="Hide from my feed"
                        wire:click="hideEntry('{{ $item['entry_id'] }}')"
                    />
                </div>

                @if (filled($card['title']))
                    <div class="sn-feed-title">{{ $card['title'] }}</div>
                @endif
                @if (filled($card['body']))
                    <div class="sn-feed-body">{{ $card['body'] }}</div>
                @endif

                @if ($item['reactions'] !== [] || $item['can_comment'] || $item['can_report'])
                    <div class="sn-feed-bar">
                        @foreach ($item['reactions'] as $key => $label)
                            @php($count = $card['reactions'][$key] ?? 0)
                            <button type="button"
                                    class="sn-feed-reaction {{ in_array($key, $card['my_reactions'], true) ? 'is-mine' : '' }}"
                                    wire:click="toggleReaction('{{ $item['entry_id'] }}', '{{ $key }}')"
                                    title="{{ $label }}">
                                <span>{{ str($label)->before(' ') }}</span>
                                @if ($count > 0)<span>{{ $count }}</span>@endif
                            </button>
                        @endforeach

                        <span class="sn-feed-spacer"></span>

                        @if ($item['can_comment'])
                            {{ ($this->commentAction)(['entry' => $item['entry_id']]) }}
                        @endif
                        @if ($item['can_report'])
                            {{ ($this->reportAction)(['entry' => $item['entry_id']]) }}
                        @endif
                    </div>
                @endif

                @if ($card['comments'] !== [])
                    <div class="sn-feed-comments">
                        @foreach ($card['comments'] as $comment)
                            <div class="sn-feed-comment"><strong>{{ $comment['author'] }}</strong> {{ $comment['body'] }}</div>
                        @endforeach
                        @if ($card['comment_count'] > count($card['comments']))
                            <div class="sn-feed-more-comments">and {{ $card['comment_count'] - count($card['comments']) }} more {{ str('comment')->plural($card['comment_count'] - count($card['comments'])) }}</div>
                        @endif
                    </div>
                @endif
            </x-filament::section>
        @empty
            <div class="sn-feed-empty">
                <x-filament::icon icon="heroicon-o-rectangle-stack" style="width: 2.5rem; height: 2.5rem; margin: 0 auto .75rem;" />
                Your feed is empty for now. Follow people and join clubs to see what they share.
            </div>
        @endforelse

        @if ($this->hasMore())
            <div class="sn-feed-load">
                <x-filament::button color="gray" wire:click="loadMore" wire:target="loadMore">Load more</x-filament::button>
            </div>
        @endif
    </div>

</x-filament-panels::page>
