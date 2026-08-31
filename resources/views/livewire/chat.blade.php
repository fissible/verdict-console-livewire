<section data-verdict-console-livewire="chat" @if ($polling) wire:poll.{{ $interval }}s @endif>
    <ol data-messages>
        @foreach ($thread?->messages ?? [] as $message)
            @if (in_array($message->role, ['user', 'assistant'], true) && $message->content !== null && $message->content !== '')
                <li data-role="{{ $message->role }}">{{ $message->content }}</li>
            @endif
        @endforeach
    </ol>

    <div wire:stream="reply" data-reply></div>

    @foreach ($cards as $card)
        <article data-approval-card="{{ $card->id }}">
            @if ($card->reason !== null && $card->reason !== '')
                <p>{{ $card->reason }}</p>
            @endif

            @foreach ($card->verbs as $verb)
                @if ($verb->value === 'approve')
                    <button type="button" data-verb="approve" wire:click="approve('{{ $card->id }}')">Approve</button>
                @elseif ($verb->value === 'reject')
                    <button type="button" data-verb="reject" wire:click="reject('{{ $card->id }}')">Reject</button>
                @else
                    <button type="button" data-verb="{{ $verb->value }}" disabled>Close</button>
                @endif
            @endforeach
        </article>
    @endforeach

    <form wire:submit="send" data-chat-form>
        <textarea wire:model="prompt"></textarea>
        <button type="submit">Send</button>
    </form>
</section>
