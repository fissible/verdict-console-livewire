<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Approvals\ApprovalSurfaceContract;
use Fissible\VerdictConsole\Approvals\ApprovalVerb;
use Fissible\VerdictConsole\Approvals\PendingApproval as StoredPendingApproval;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsoleLivewire\Livewire\Inbox;
use Fissible\VerdictConsoleLivewire\Tests\EndToEndTestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision as AiDecision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;

/**
 * VC-25: the reactive upgrade of the core's approval inbox widget — a live-updating list over the
 * SAME scoped pending-approval query, whose verbs drive the SAME core resolution service (VC-6).
 * Rows of every lifecycle state render exactly as the core widget's contract renders them; the
 * only thing this surface adds is that new pauses and state changes arrive without a reload.
 * Fixtures are this file's own.
 */
const LIVE_INBOX_ENTRY_KEY = 'live-inbox@v1';
const LIVE_INBOX_ORDER_ID = 5151;

final class LiveInboxLedger
{
    public int $executions = 0;
}

final readonly class LiveInboxOrder
{
    public function __construct(public int $id) {}
}

final class LiveInboxCancelOrderTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Cancel an order by id.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'The Verdict-bound tool handles this.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

function liveInboxBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('live-inbox.orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'live-inbox.orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): LiveInboxOrder => new LiveInboxOrder((int) $e->proposal->arguments['order_id']),
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'live-inbox-target',
                    identityUsing: fn (ActionEnvelope $e, LiveInboxOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, LiveInboxOrder $t): array => ['order_id' => $t->id], reason: 'Cancelling an order needs confirmation.')
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(LiveInboxLedger::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(new LiveInboxCancelOrderTool, 'live-inbox.orders.cancel', new ActionContext('live-inbox-customer'));
}

final class LiveInboxAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'Help customers with their orders.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [liveInboxBoundTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [app(VerdictApprovalMiddleware::class)];
    }

    public function provider(): string
    {
        return EndToEndTestCase::PROVIDER;
    }

    public function maxSteps(): int
    {
        return 3;
    }
}

final class LiveInboxParticipants implements ConversationParticipants
{
    public function referenceFor(object $participant): string
    {
        if (! $participant instanceof GenericUser) {
            throw new LogicException('Unexpected live-inbox participant.');
        }

        return 'user:'.$participant->getAuthIdentifier();
    }

    public function resolve(string $reference): object
    {
        if (! preg_match('/^user:(\d+)$/', $reference, $matches)) {
            throw new LogicException('Unknown live-inbox participant reference.');
        }

        return liveInboxUser((int) $matches[1]);
    }
}

function liveInboxUser(int $id = 7): GenericUser
{
    return new GenericUser(['id' => $id]);
}

/**
 * Drive one real pause outside any component, as another process would.
 *
 * Multi-pause tests must register ONE combined sequence and pass fake: false — Http::fake
 * handlers answer first-registered-first, so a second per-call fake never answers while the
 * first sequence still holds its leftover resume push (the VC-21 measured fact).
 */
function liveInboxPause(string $toolCallId, bool $fake = true): StoredPendingApproval
{
    if ($fake) {
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(test()->toolCallResponse($toolCallId, 'LiveInboxCancelOrderTool', ['order_id' => LIVE_INBOX_ORDER_ID]))
            ->push(test()->textResponse('Done — your order is cancelled.'))]);
    }

    $paused = (new LiveInboxAgent)->forParticipant(liveInboxUser())->prompt('Please cancel order '.LIVE_INBOX_ORDER_ID.'.');

    expect($paused->hasPendingApprovals())->toBeTrue('Fixture: the confirmation-gated run must pause.');

    return StoredPendingApproval::query()->where('tool_call_id', $toolCallId)->sole();
}

/** The one row's own element, DOM-extracted so its controls are judged and nothing else's. */
function liveInboxRowHtml(string $html, string $approvalId): string
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    foreach ((new DOMXPath($document))->query('//*[@data-approval="'.$approvalId.'"]') ?: [] as $node) {
        if ($node instanceof DOMElement) {
            return (string) $document->saveHTML($node);
        }
    }

    throw new LogicException('No rendered inbox row found for approval ['.$approvalId.'].');
}

/** @return list<ApprovalVerb> the verbs THIS row offers, never another control's */
function liveInboxRenderedVerbs(string $html, string $approvalId): array
{
    preg_match_all('/data-verb="([^"]+)"/', liveInboxRowHtml($html, $approvalId), $matches);

    return array_map(fn (string $verb): ApprovalVerb => ApprovalVerb::from($verb), $matches[1]);
}

beforeEach(function (): void {
    $this->migrateChatTables();
    $this->app->instance(LiveInboxLedger::class, new LiveInboxLedger);
    $this->app->instance(ConversationParticipants::class, new LiveInboxParticipants);

    // The two host wirings the real stack pauses on (measured in VC-24): a permitting capability
    // authorizer, and a participants binding that round-trips the GenericUser.
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('live-inbox test');
        }
    });

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register(LIVE_INBOX_ENTRY_KEY, fn (): LiveInboxAgent => new LiveInboxAgent, fn (Agent $agent): bool => $agent instanceof LiveInboxAgent);
});

it('registers the inbox component under the package namespace', function (): void {
    $component = Livewire::test('verdict-console-livewire::inbox');

    $component->assertSeeHtml('data-verdict-console-livewire="inbox"');

    expect($component->instance())->toBeInstanceOf(Inbox::class, 'The alias must resolve THIS component, not an impostor.');
});

it('renders a real paused row as pending with its decision verbs', function (): void {
    $row = liveInboxPause('call_li_render');
    $this->actingAs(liveInboxUser());

    $component = Livewire::test(Inbox::class);

    $component->assertSeeHtml('data-approval="'.$row->id.'"')
        ->assertSee('Cancelling an order needs confirmation.');

    $rowHtml = liveInboxRowHtml($component->html(), (string) $row->id);

    expect($rowHtml)->toContain('data-state="pending"')
        ->and(liveInboxRenderedVerbs($component->html(), (string) $row->id))
        ->toEqualCanonicalizing([ApprovalVerb::Approve, ApprovalVerb::Reject])
        // The controls are live wires into THIS row's actions, not inert markup.
        ->and($rowHtml)->toContain('wire:click="approve(\''.$row->id.'\')"')
        ->and($rowHtml)->toContain('wire:click="reject(\''.$row->id.'\')"');
});

/** The acceptance line, verbatim: a new pending approval appears without a reload. */
it('shows a new pending approval without a reload', function (): void {
    $this->actingAs(liveInboxUser());
    $component = Livewire::test(Inbox::class);

    $component->assertDontSeeHtml('data-approval=');

    // Another process pauses a run while this operator's inbox is already open.
    $row = liveInboxPause('call_li_appears');

    // The poll tick is a Livewire refresh; the component must pick the row up with no remount.
    $component->call('$refresh')
        ->assertSeeHtml('data-approval="'.$row->id.'"')
        ->assertSeeHtml('data-state="pending"');
});

it('approves through the core resolution service and the row leaves the pending state', function (): void {
    $row = liveInboxPause('call_li_approve');
    Gate::define('approve-verdict-action', fn (): bool => true);
    $this->actingAs(liveInboxUser());

    $component = Livewire::test(Inbox::class)
        ->call('approve', (string) $row->id)
        ->assertSet('status', 'approved');

    expect(app(LiveInboxLedger::class)->executions)->toBe(1, 'The approved tool executes exactly once.')
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_approve')->value('status'))->toBe('consumed')
        // The core resolution service is the only writer of this counter.
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1);

    // The row re-renders from the live status read, without a reload.
    expect(liveInboxRowHtml($component->html(), (string) $row->id))
        ->toContain('data-state="already_decided"');

    Http::assertSentCount(2);
});

it('rejects through the core resolution service without executing', function (): void {
    $row = liveInboxPause('call_li_reject');
    Gate::define('approve-verdict-action', fn (): bool => true);
    $this->actingAs(liveInboxUser());

    Livewire::test(Inbox::class)
        ->call('reject', (string) $row->id)
        ->assertSet('status', 'rejected');

    expect(app(LiveInboxLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_reject')->value('status'))->toBe('rejected');

    // Measured on the core reject round trip: a bare rejection ends the turn without another
    // model call.
    Http::assertSentCount(1);
});

/** The inbox is where close lives (core design): a lapsed receipt offers it, and it closes for real. */
it('closes a lapsed receipt without deciding it', function (): void {
    $row = liveInboxPause('call_li_close');
    Gate::define('approve-verdict-action', fn (): bool => true);
    DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_close')->update(['expires_at' => now()->subMinute()]);
    $this->actingAs(liveInboxUser());

    $component = Livewire::test(Inbox::class);

    expect(liveInboxRowHtml($component->html(), (string) $row->id))->toContain('data-state="lapsed_undecided"')
        ->and(liveInboxRenderedVerbs($component->html(), (string) $row->id))->toBe([ApprovalVerb::Close])
        ->and(liveInboxRowHtml($component->html(), (string) $row->id))->toContain('wire:click="close(\''.$row->id.'\')"');

    $component->call('close', (string) $row->id)
        ->assertSet('status', 'closed');

    expect(app(LiveInboxLedger::class)->executions)->toBe(0, 'Close only sends Laravel AI a rejection.')
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_close')->value('status'))->toBe('pending', 'Close never mutates the receipt.')
        // The continuation was genuinely attempted: only the core service spends an attempt...
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1);

    // ...and the refusal genuinely reached Laravel AI: the turn is no longer resumable, which a
    // second close relays as the measured already-resolved answer.
    Livewire::test(Inbox::class)
        ->call('close', (string) $row->id)
        ->assertSet('status', 'already_resolved');

    Http::assertSentCount(1);
});

it('relays a close that found a live decision still available, deciding nothing', function (): void {
    $row = liveInboxPause('call_li_close_live');
    Gate::define('approve-verdict-action', fn (): bool => true);
    $this->actingAs(liveInboxUser());

    Livewire::test(Inbox::class)
        ->call('close', (string) $row->id)
        ->assertSet('status', 'decision_still_available');

    expect(app(LiveInboxLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_close_live')->value('status'))->toBe('pending');
});

/** ADR 0001's verb invariant across lifecycle states, against the shared contract and real reader. */
it('renders exactly the verb set the surface contract resolves for every row', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push($this->toolCallResponse('call_li_matrix_pending', 'LiveInboxCancelOrderTool', ['order_id' => LIVE_INBOX_ORDER_ID]))
        ->push($this->toolCallResponse('call_li_matrix_lapsed', 'LiveInboxCancelOrderTool', ['order_id' => LIVE_INBOX_ORDER_ID]))]);
    $pending = liveInboxPause('call_li_matrix_pending', fake: false);
    $lapsed = liveInboxPause('call_li_matrix_lapsed', fake: false);
    DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_matrix_lapsed')->update(['expires_at' => now()->subMinute()]);
    $this->actingAs(liveInboxUser());

    $component = Livewire::test(Inbox::class);
    $contract = app(ApprovalSurfaceContract::class);
    $reader = app(ApprovalStatusReader::class);

    foreach ([$pending, $lapsed] as $row) {
        $contract->assertRendered(
            liveInboxRenderedVerbs($component->html(), (string) $row->id),
            $row->refresh(),
            $reader->statusFor((string) $row->receipt_id),
        );
    }

    expect(liveInboxRenderedVerbs($component->html(), (string) $pending->id))->not->toBeEmpty();
});

it('refuses a decision for a guest, spending nothing', function (): void {
    $row = liveInboxPause('call_li_guest');

    Livewire::test(Inbox::class)
        ->call('approve', (string) $row->id)
        ->assertForbidden();

    expect(app(LiveInboxLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_guest')->value('status'))->toBe('pending');
});

it('refuses a decision the host Gate denies, spending nothing', function (): void {
    $row = liveInboxPause('call_li_denied');
    Gate::define('approve-verdict-action', fn (): bool => false);
    $this->actingAs(liveInboxUser());

    Livewire::test(Inbox::class)
        ->call('approve', (string) $row->id)
        ->assertForbidden();

    expect(app(LiveInboxLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_denied')->value('status'))->toBe('pending');
});

it('answers not-found for a row that does not exist', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => throw new LogicException('The Gate must not be consulted for a row that is not there.'));
    $this->actingAs(liveInboxUser());

    Livewire::test(Inbox::class)
        ->call('approve', 'not-a-row')
        ->assertNotFound();
});

/** VC-12's boundary survives reactivity: a foreign-scoped row is neither rendered nor actionable. */
it('neither renders nor resolves a row outside the host scope', function (): void {
    $row = liveInboxPause('call_li_scoped');
    Gate::define('approve-verdict-action', fn (): bool => throw new LogicException('The Gate must not be consulted for a hidden row.'));
    app()->instance(ApprovalScope::class, new class implements ApprovalScope
    {
        public function apply(Builder $query): Builder
        {
            return $query->where('conversation_id', 'another-tenant');
        }
    });
    $this->actingAs(liveInboxUser());

    $component = Livewire::test(Inbox::class);

    $component->assertDontSeeHtml('data-approval="'.$row->id.'"');

    $component->call('approve', (string) $row->id)->assertNotFound();

    expect(app(LiveInboxLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_scoped')->value('status'))->toBe('pending');
});

/** Every lifecycle state the core widget renders survives the reactive surface, unfiltered. */
it('renders unavailable and not-console-actionable rows exactly as the core states them', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push($this->toolCallResponse('call_li_vanished', 'LiveInboxCancelOrderTool', ['order_id' => LIVE_INBOX_ORDER_ID]))
        ->push($this->toolCallResponse('call_li_stranded', 'LiveInboxCancelOrderTool', ['order_id' => LIVE_INBOX_ORDER_ID]))]);
    $vanished = liveInboxPause('call_li_vanished', fake: false);
    DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_vanished')->delete();
    $stranded = liveInboxPause('call_li_stranded', fake: false);
    $stranded->forceFill(['resumability' => 'unresumable', 'unresumable_reason' => 'agent_unresolvable', 'resolver_key' => null])->save();
    $this->actingAs(liveInboxUser());

    $component = Livewire::test(Inbox::class);

    $vanishedHtml = liveInboxRowHtml($component->html(), (string) $vanished->id);
    $strandedHtml = liveInboxRowHtml($component->html(), (string) $stranded->id);

    expect($vanishedHtml)->toContain('data-state="receipt_unavailable"')
        ->and($vanishedHtml)->toContain('receipt unavailable')
        // A drivable row whose receipt vanished still offers the run its non-authorizing way out.
        ->and(liveInboxRenderedVerbs($component->html(), (string) $vanished->id))->toBe([ApprovalVerb::Close])
        ->and($vanishedHtml)->toContain('wire:click="close(\''.$vanished->id.'\')"')
        ->and($strandedHtml)->toContain('data-state="not_console_actionable"')
        ->and($strandedHtml)->toContain('data-unresumable-reason="agent_unresolvable"')
        ->and(liveInboxRenderedVerbs($component->html(), (string) $stranded->id))->toBe([], 'A row this console cannot drive offers nothing.');
});

/** An approve that arrives after the deadline is not actionable — the service's null, surfaced honestly. */
it('reports not-actionable for a decision on a lapsed receipt', function (): void {
    $row = liveInboxPause('call_li_lapsed_approve');
    Gate::define('approve-verdict-action', fn (): bool => true);
    DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_lapsed_approve')->update(['expires_at' => now()->subMinute()]);
    $this->actingAs(liveInboxUser());

    Livewire::test(Inbox::class)
        ->call('approve', (string) $row->id)
        ->assertSet('status', 'not_actionable');

    expect(app(LiveInboxLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_lapsed_approve')->value('status'))->toBe('pending');
});

/**
 * The close race the outcome vocabulary exists for: another actor decided AND the turn already
 * resumed, so close relays Laravel AI's measured already-resolved answer instead of claiming a
 * close it did not perform.
 */
it('relays an already-resolved close instead of claiming success', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push($this->toolCallResponse('call_li_resolved', 'LiveInboxCancelOrderTool', ['order_id' => LIVE_INBOX_ORDER_ID]))
        ->push($this->textResponse('Understood, leaving the order alone.'))]);
    Gate::define('approve-verdict-action', fn (): bool => true);
    $agent = (new LiveInboxAgent)->forParticipant(liveInboxUser());
    $paused = $agent->prompt('Please cancel order '.LIVE_INBOX_ORDER_ID.'.');

    expect($paused->hasPendingApprovals())->toBeTrue('Fixture: the run must pause.');

    $row = StoredPendingApproval::query()->where('tool_call_id', 'call_li_resolved')->sole();

    // Decided outside the console, and the turn resumed by the host itself — both proven, so a
    // component mapping "decided" straight to already_resolved cannot borrow this fixture.
    app(VerdictManager::class)->approvals()->reject((string) $row->receipt_id, 'call_li_resolved', 'someone-else');

    expect(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_li_resolved')->value('status'))->toBe('rejected');

    $agent->prompt(Decisions::from(['call_li_resolved' => AiDecision::reject()]));

    // A bare rejection resume makes no model call (the measured core fact); the pause request
    // stays the only one. The continuation's real proof is below: only Laravel AI's store can
    // answer the console close with already-resolved.
    Http::assertSentCount(1);

    $this->actingAs(liveInboxUser());

    Livewire::test(Inbox::class)
        ->call('close', (string) $row->id)
        ->assertSet('status', 'already_resolved');

    expect(app(LiveInboxLedger::class)->executions)->toBe(0)
        // The console genuinely attempted the continuation; already_resolved is Laravel AI's
        // measured answer to it, not a status guessed from the receipt.
        ->and(StoredPendingApproval::query()->where('tool_call_id', 'call_li_resolved')->sole()->resume_attempts)->toBe(1);
});

/** The decided row's verb answers to the same contract as every other state. */
it('offers only close on a decided row, per the surface contract', function (): void {
    $row = liveInboxPause('call_li_decided_verbs');
    Gate::define('approve-verdict-action', fn (): bool => true);
    $this->actingAs(liveInboxUser());

    $component = Livewire::test(Inbox::class)->call('approve', (string) $row->id);

    app(ApprovalSurfaceContract::class)->assertRendered(
        liveInboxRenderedVerbs($component->html(), (string) $row->id),
        $row->refresh(),
        app(ApprovalStatusReader::class)->statusFor((string) $row->receipt_id),
    );

    expect(liveInboxRenderedVerbs($component->html(), (string) $row->id))->toBe([ApprovalVerb::Close]);
});

/** The core query's contract carries through: newest pause first. */
it('lists newer pauses before older ones', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push($this->toolCallResponse('call_li_older', 'LiveInboxCancelOrderTool', ['order_id' => LIVE_INBOX_ORDER_ID]))
        ->push($this->toolCallResponse('call_li_newer', 'LiveInboxCancelOrderTool', ['order_id' => LIVE_INBOX_ORDER_ID]))]);
    Carbon::setTestNow('2026-08-31 10:00:00');
    $older = liveInboxPause('call_li_older', fake: false);
    Carbon::setTestNow('2026-08-31 10:00:05');
    $newer = liveInboxPause('call_li_newer', fake: false);
    Carbon::setTestNow();
    $this->actingAs(liveInboxUser());

    $html = Livewire::test(Inbox::class)->html();

    $newerAt = strpos($html, 'data-approval="'.$newer->id.'"');
    $olderAt = strpos($html, 'data-approval="'.$older->id.'"');

    expect($newerAt)->not->toBeFalse()
        ->and($olderAt)->not->toBeFalse()
        ->and($newerAt)->toBeLessThan($olderAt, 'The inbox lists newest first, as the core query orders it.');
});

/** VC-23's transport decision: poll at the configured interval by default, listen when broadcasting. */
it('polls only while the transport is polling, at the configured interval', function (): void {
    $this->actingAs(liveInboxUser());

    Livewire::test(Inbox::class)->assertSeeHtml('wire:poll.5s');

    config()->set('verdict-console-livewire.polling.interval_seconds', 9);

    Livewire::test(Inbox::class)->assertSeeHtml('wire:poll.9s');

    config()->set('verdict-console-livewire.transport', 'broadcast');

    $html = Livewire::test(Inbox::class)->html();

    expect($html)->not->toContain('wire:poll');
});
