{{--
    Chat de una conversación, dentro del slide-over "Open".
    El panel no compila Tailwind propio, así que el diseño usa los componentes de
    Filament y este CSS plano con sus variables de color (--primary-*, --gray-*).
--}}
<div class="sn-chat" wire:poll.15s>
    <style>
        .sn-chat { display: flex; flex-direction: column; gap: 1rem; }
        .sn-chat-messages { display: flex; flex-direction: column; gap: .75rem; }
        .sn-chat-msg { display: flex; flex-direction: column; align-items: flex-start; max-width: 85%; }
        .sn-chat-msg.is-mine { align-self: flex-end; align-items: flex-end; }
        .sn-chat-meta { display: flex; align-items: center; gap: .375rem; font-size: .75rem; color: var(--gray-500); margin-bottom: .25rem; }
        .sn-chat-bubble { padding: .5rem .75rem; border-radius: .875rem; border-top-left-radius: .25rem; white-space: pre-line; overflow-wrap: anywhere; background: var(--gray-100); color: var(--gray-950); }
        .dark .sn-chat-bubble { background: var(--gray-800); color: var(--gray-100); }
        .sn-chat-msg.is-mine .sn-chat-bubble { border-top-left-radius: .875rem; border-top-right-radius: .25rem; background: var(--primary-600); color: #fff; }
        .sn-chat-bubble.is-unreadable { font-style: italic; opacity: .7; }
        .sn-chat-tools { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem; margin-top: .25rem; }
        .sn-chat-reaction { display: inline-flex; align-items: center; gap: .25rem; padding: .0625rem .5rem; border-radius: 9999px; font-size: .8125rem; line-height: 1.5; border: 1px solid var(--gray-200); background: transparent; cursor: pointer; color: inherit; }
        .dark .sn-chat-reaction { border-color: var(--gray-700); }
        .sn-chat-reaction.is-mine { border-color: var(--primary-500); background: color-mix(in srgb, var(--primary-500) 12%, transparent); }
        .sn-chat-reaction.is-picker { opacity: .35; border-color: transparent; padding: .0625rem .25rem; }
        .sn-chat-msg:hover .sn-chat-reaction.is-picker { opacity: .8; }
        .sn-chat-reaction.is-picker:hover { opacity: 1; }
        .sn-chat-empty { padding: 2rem 0; text-align: center; color: var(--gray-500); }
        .sn-chat-composer { display: flex; flex-direction: column; gap: .5rem; border-top: 1px solid var(--gray-200); padding-top: 1rem; }
        .dark .sn-chat-composer { border-color: var(--gray-700); }
        .sn-chat-composer textarea { width: 100%; resize: vertical; min-height: 4.5rem; }
        .sn-chat-composer-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
        .sn-chat-lock { display: inline-flex; align-items: center; gap: .375rem; font-size: .875rem; color: var(--gray-600); }
        .dark .sn-chat-lock { color: var(--gray-400); }
    </style>

    <div class="sn-chat-messages">
        @forelse ($messages as $message)
            <div class="sn-chat-msg {{ $message['mine'] ? 'is-mine' : '' }}" wire:key="msg-{{ $message['id'] }}">
                <div class="sn-chat-meta">
                    <span>{{ $message['mine'] ? 'You' : $message['sender'] }}</span>
                    <span>·</span>
                    <span title="{{ $message['sent_at']?->toDayDateTimeString() }}">{{ $message['sent_at']?->diffForHumans() }}</span>
                    @if ($message['encrypted'])
                        <x-filament::icon icon="heroicon-m-lock-closed" class="h-3 w-3" style="width: .75rem; height: .75rem;" title="Encrypted" />
                    @endif
                </div>

                <div class="sn-chat-bubble {{ $message['body'] === null ? 'is-unreadable' : '' }}">{{ $message['body'] ?? 'This message can no longer be decrypted.' }}</div>

                <div class="sn-chat-tools">
                    @foreach ($message['reactions'] as $reaction)
                        <button type="button"
                                class="sn-chat-reaction {{ $reaction['mine'] ? 'is-mine' : '' }}"
                                wire:click="toggleReaction('{{ $message['id'] }}', '{{ $reaction['emoji'] }}')"
                                title="{{ $reaction['mine'] ? 'Remove your reaction' : 'React' }}">
                            <span>{{ $reaction['emoji'] }}</span><span>{{ $reaction['count'] }}</span>
                        </button>
                    @endforeach

                    @foreach ($reactions as $emoji)
                        @unless (collect($message['reactions'])->contains('emoji', $emoji))
                            <button type="button" class="sn-chat-reaction is-picker"
                                    wire:click="toggleReaction('{{ $message['id'] }}', '{{ $emoji }}')"
                                    title="React with {{ $emoji }}">{{ $emoji }}</button>
                        @endunless
                    @endforeach

                    @if ($message['mine'])
                        <x-filament::icon-button
                            icon="heroicon-m-trash"
                            color="gray"
                            size="sm"
                            label="Delete message"
                            wire:click="deleteMessage('{{ $message['id'] }}')"
                            wire:confirm="Delete this message for everyone?"
                        />
                    @endif
                </div>
            </div>
        @empty
            <div class="sn-chat-empty">No messages yet. Say hello!</div>
        @endforelse
    </div>

    <form class="sn-chat-composer" wire:submit="sendReply">
        <x-filament::input.wrapper :valid="! $errors->has('reply')">
            <textarea class="fi-input" wire:model="reply" rows="3" placeholder="Write a message…"
                      x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.sendReply() }"></textarea>
        </x-filament::input.wrapper>

        @error('reply')
            <p style="font-size: .875rem; color: var(--danger-600);">{{ $message }}</p>
        @enderror

        <div class="sn-chat-composer-row">
            <label class="sn-chat-lock">
                <x-filament::input.checkbox wire:model="replyEncrypted" />
                <span>Encrypt this message</span>
            </label>

            <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:target="sendReply">
                Send
            </x-filament::button>
        </div>
    </form>
</div>
