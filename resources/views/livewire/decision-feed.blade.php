<section data-verdict-console-livewire="decision-feed" data-recording="{{ $result->recording->value }}" @if ($polling) wire:poll.{{ $interval }}s @endif>
    @if ($result->recording->value === 'off')
        <p data-recording-off>recording is off — blank by config.</p>
    @elseif ($result->recording->value === 'chained')
        <p data-recording-chained>A chained sink{{ $result->recordedBy === null ? '' : " ({$result->recordedBy})" }} is configured; decisions are not readable from this table.</p>
    @elseif ($result->recording->value === 'elsewhere')
        <p data-recording-elsewhere>Evidence is recorded elsewhere by {{ $result->recordedBy }}.</p>
    @elseif ($result->records === [])
        <p data-empty>No decisions have been recorded.</p>
    @else
        <ol data-decisions>
            @foreach ($entries as $entry)
                <li data-record="{{ $entry->id }}" data-disposition="{{ $entry->disposition }}">
                    <time data-field="recorded_at" datetime="{{ $entry->recordedAt->format(DATE_ATOM) }}">{{ $entry->recordedAt->format(DATE_ATOM) }}</time>
                    <span data-field="capability">{{ $entry->capability }}</span>
                    <span data-field="stage">{{ $entry->stage }}</span>
                    <span data-field="disposition">{{ $entry->disposition }}</span>
                    @if ($entry->claimType !== null)
                        <span data-field="claim_type">{{ $entry->claimType }}</span>
                    @endif
                    @if ($entry->invocationId !== null)
                        <span data-field="invocation_id">{{ $entry->invocationId }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
