<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleLivewire\Livewire;

use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceRecord;
use Fissible\VerdictConsoleLivewire\Transport;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Reactive, newest-first slice of the console core's evidence read boundary. */
final class DecisionFeed extends Component
{
    public int $limit = 25;

    public function mount(int $limit = 25): void
    {
        $this->limit = $limit;
    }

    public function render(): View
    {
        $result = app(EvidenceQuery::class)->search(new EvidenceFilter);
        $records = $result->records;

        usort($records, fn (EvidenceRecord $left, EvidenceRecord $right): int => $right->recordedAt <=> $left->recordedAt);

        return view('verdict-console-livewire::livewire.decision-feed', [
            'result' => $result,
            'entries' => array_slice($records, 0, max(1, $this->limit)),
            'polling' => Transport::fromConfig(config('verdict-console-livewire.transport')) === Transport::Polling,
            'interval' => (int) config('verdict-console-livewire.polling.interval_seconds'),
        ]);
    }
}
