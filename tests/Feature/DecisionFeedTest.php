<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceQueryResult;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsoleLivewire\Livewire\DecisionFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * VC-26: the live decision feed — the reactive, newest-first slice of the VC-13 evidence read
 * boundary. Everything renders from real rows in Verdict's published schema under a real recorder
 * configuration; the honest recording states outrank the rows, exactly as the core audit page
 * states them. "Streaming" is the poll tick: a decision recorded by another process appears with
 * no reload. Fixtures are this file's own.
 */
const FEED_EVIDENCE_STUBS = [
    'create_verdict_evidence_table.php.stub',
    'add_provenance_to_verdict_evidence_table.php.stub',
    'add_invocation_id_to_verdict_evidence_table.php.stub',
    'add_tool_kind_to_verdict_evidence_table.php.stub',
    'add_configuration_fingerprint_to_verdict_evidence_table.php.stub',
    'add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub',
    'add_target_source_to_verdict_evidence_table.php.stub',
    'add_tool_description_fingerprints_to_verdict_evidence_table.php.stub',
    'add_record_identity_to_verdict_evidence_table.php.stub',
    'add_intent_id_to_verdict_evidence_table.php.stub',
];

beforeEach(function (): void {
    config()->set('verdict.evidence.table', 'feed_evidence');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $migrations = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';

    foreach (FEED_EVIDENCE_STUBS as $stub) {
        (require $migrations.'/'.$stub)->up();
    }
});

afterEach(function (): void {
    Schema::dropIfExists('feed_evidence');
});

/** Same guard the core evidence suites carry: a new Verdict stub must not leave this fixture behind. */
it('builds its fixture from every evidence-table stub the installed Verdict publishes', function (): void {
    $published = array_map(basename(...), glob(dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/*verdict_evidence_table.php.stub') ?: []);

    expect(FEED_EVIDENCE_STUBS)->toEqualCanonicalizing($published);
});

/** @param array<string, mixed> $attributes */
function insertFeedDecision(array $attributes): void
{
    DB::table('feed_evidence')->insert([
        'id' => $attributes['id'],
        'record_type' => $attributes['record_type'] ?? 'decision',
        'capability' => $attributes['capability'] ?? 'orders.cancel',
        'stage' => $attributes['stage'] ?? 'proposal',
        'disposition' => $attributes['disposition'] ?? 'permit',
        'reason' => $attributes['reason'] ?? null,
        'claim_type' => $attributes['claim_type'] ?? null,
        'record_digest' => $attributes['record_digest'] ?? null,
        'invocation_id' => $attributes['invocation_id'] ?? null,
        'recorded_at' => $attributes['recorded_at'],
    ]);
}

/** Decisions a minute apart, newest LAST in the table so "newest first" is a real ordering claim. */
function seedFeedDecisions(int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        insertFeedDecision([
            'id' => sprintf('feed-decision-%02d', $i),
            'disposition' => $i % 3 === 0 ? 'deny' : 'permit',
            'claim_type' => $i % 3 === 0 ? 'policy.denied' : null,
            'recorded_at' => sprintf('2026-08-31 10:%02d:00', $i),
        ]);
    }
}

/** @return list<string> the rendered record ids, in document order */
function feedRenderedIds(string $html): array
{
    preg_match_all('/data-record="([^"]+)"/', $html, $matches);

    return $matches[1];
}

/** The one entry's own element, DOM-extracted so its fields are judged and nothing else's. */
function feedEntryHtml(string $html, string $recordId): string
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    foreach ((new DOMXPath($document))->query('//*[@data-record="'.$recordId.'"]') ?: [] as $node) {
        if ($node instanceof DOMElement) {
            return (string) $document->saveHTML($node);
        }
    }

    throw new LogicException('No rendered feed entry found for record ['.$recordId.'].');
}

it('registers the decision-feed component under the package namespace', function (): void {
    $component = Livewire::test('verdict-console-livewire::decision-feed');

    $component->assertSeeHtml('data-verdict-console-livewire="decision-feed"');

    expect($component->instance())->toBeInstanceOf(DecisionFeed::class, 'The alias must resolve THIS component, not an impostor.');
});

it('renders the newest decisions first by recording time, capped at the feed limit', function (): void {
    seedFeedDecisions(30);
    // The highest identifier deliberately carries an OLD timestamp: newest means recorded_at,
    // never identifier order.
    insertFeedDecision(['id' => 'feed-decision-99-old', 'recorded_at' => '2026-08-31 09:00:00']);

    $component = Livewire::test(DecisionFeed::class);
    $ids = feedRenderedIds($component->html());

    expect($ids)->toHaveCount(25, 'The default feed slice is the latest 25.')
        ->and($ids[0])->toBe('feed-decision-30')
        ->and($ids[24])->toBe('feed-decision-06')
        ->and($ids)->not->toContain('feed-decision-05')
        ->and($ids)->not->toContain('feed-decision-99-old', 'An old decision with a high id must not jump the feed.');

    // The sampled entry's OWN element carries the display-safe fields — a header or another row
    // counts for nothing.
    $entry = feedEntryHtml($component->html(), 'feed-decision-30');

    expect($entry)->toContain('data-disposition="deny"')
        ->and($entry)->toContain('data-field="capability"')
        ->and($entry)->toContain('data-field="stage"')
        ->and($entry)->toContain('data-field="disposition"')
        ->and($entry)->toContain('data-field="recorded_at"');
});

/** The acceptance line: a disposition recorded by another process streams into the open feed. */
it('shows a newly recorded disposition without a reload', function (): void {
    $component = Livewire::test(DecisionFeed::class);

    $component->assertDontSeeHtml('data-record=');

    insertFeedDecision(['id' => 'feed-decision-live', 'disposition' => 'deny', 'recorded_at' => '2026-08-31 11:00:00']);

    $component->call('$refresh')
        ->assertSeeHtml('data-record="feed-decision-live"')
        ->assertSeeHtml('data-disposition="deny"');
});

it('honours a smaller feed limit', function (): void {
    seedFeedDecisions(10);

    $ids = feedRenderedIds(Livewire::test(DecisionFeed::class, ['limit' => 5])->html());

    expect($ids)->toHaveCount(5)
        ->and($ids[0])->toBe('feed-decision-10')
        ->and($ids[4])->toBe('feed-decision-06');
});

/**
 * The read boundary made falsifiable: with EvidenceQuery bound to an answer the table and the
 * config cannot produce — an elsewhere state under a Database-recorder config, carrying a record
 * no table holds — the feed must render the CONTRACT's answer. A component reading the table or
 * re-deriving states from config renders something else entirely.
 */
it('renders whatever the bound evidence contract answers, never the table or config', function (): void {
    seedFeedDecisions(3);
    app()->instance(EvidenceQuery::class, new class implements EvidenceQuery
    {
        public function search(EvidenceFilter $filter): EvidenceQueryResult
        {
            return new EvidenceQueryResult(
                recording: EvidenceRecordingState::Elsewhere,
                records: [],
                recordedBy: 'Fake\\NotDerivableFromConfig',
            );
        }
    });

    $component = Livewire::test(DecisionFeed::class);

    $component->assertSeeHtml('data-recording="elsewhere"')
        ->assertSee('Fake\\NotDerivableFromConfig')
        ->assertDontSeeHtml('data-record=', 'The table rows must not leak past the bound contract.');
});

/** Recording off is the boundary's answer, and it outranks any rows a table might hold. */
it('renders the recording-off config state instead of rows', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    seedFeedDecisions(3);

    $component = Livewire::test(DecisionFeed::class);

    $component->assertSeeHtml('data-recording="off"')
        ->assertSee('recording is off — blank by config.')
        ->assertDontSeeHtml('data-record=');
});

it('says when evidence is recorded elsewhere, naming the writer', function (): void {
    config()->set('verdict.evidence.writer', 'App\Evidence\ExternalWriter');
    seedFeedDecisions(3);

    $component = Livewire::test(DecisionFeed::class);

    $component->assertSeeHtml('data-recording="elsewhere"')
        ->assertSee('App\Evidence\ExternalWriter')
        ->assertDontSeeHtml('data-record=');
});

/** Recording on with nothing recorded is a different fact from recording off, and reads differently. */
it('says nothing has been recorded when recording is on and the table is empty', function (): void {
    $component = Livewire::test(DecisionFeed::class);

    $component->assertSeeHtml('data-recording="on"')
        ->assertSee('No decisions have been recorded.')
        ->assertDontSeeHtml('data-record=');

    expect($component->html())->not->toContain('recording is off');
});

/** VC-23's transport decision: poll at the configured interval by default, listen when broadcasting. */
it('polls only while the transport is polling, at the configured interval', function (): void {
    Livewire::test(DecisionFeed::class)->assertSeeHtml('wire:poll.5s');

    config()->set('verdict-console-livewire.polling.interval_seconds', 9);

    Livewire::test(DecisionFeed::class)->assertSeeHtml('wire:poll.9s');

    config()->set('verdict-console-livewire.transport', 'broadcast');

    $html = Livewire::test(DecisionFeed::class)->html();

    expect($html)->not->toContain('wire:poll');
});
