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
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\UnresolvableAgentKey;
use Fissible\VerdictConsoleLivewire\Livewire\Chat;
use Fissible\VerdictConsoleLivewire\Tests\EndToEndTestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
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
 * VC-24, the flagship: a reactive chat whose approval interrupt is a card in the thread, resolved
 * in-flow through the core services, with the resumed reply landing without a reload. The core's
 * reload-driven Blade chat is the baseline this component upgrades (design §9); every guard it
 * relies on — ownership, entry key, blank prompt, verb policy — stays in the core, and this
 * surface adds reactivity only. Fixtures are this file's own.
 */
const CARDS_ENTRY_KEY = 'cards@v1';
const CARDS_TOOL_CALL_ID = 'call_cards';
const CARDS_ORDER_ID = 4242;

final class CardsLedger
{
    public int $executions = 0;
}

final readonly class CardsOrder
{
    public function __construct(public int $id) {}
}

final class CardsCancelOrderTool implements Tool
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

function cardsBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('cards.orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'cards.orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): CardsOrder => new CardsOrder((int) $e->proposal->arguments['order_id']),
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'cards-target',
                    identityUsing: fn (ActionEnvelope $e, CardsOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, CardsOrder $t): array => ['order_id' => $t->id], reason: 'Cancelling an order needs confirmation.')
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(CardsLedger::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(new CardsCancelOrderTool, 'cards.orders.cancel', new ActionContext('cards-customer'));
}

final class CardsAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
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
        return [cardsBoundTool()];
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

/**
 * Rebuilds the GenericUser participant by its host-owned opaque reference. Without a participants
 * binding the console's default refuses, ingestion records every pause participant-unresolvable,
 * and no card is ever actionable — the same wiring every host (and every core e2e file) supplies.
 */
final class CardsParticipants implements ConversationParticipants
{
    public function referenceFor(object $participant): string
    {
        if (! $participant instanceof GenericUser) {
            throw new LogicException('Unexpected cards participant.');
        }

        return 'user:'.$participant->getAuthIdentifier();
    }

    public function resolve(string $reference): object
    {
        if (! preg_match('/^user:(\d+)$/', $reference, $matches)) {
            throw new LogicException('Unknown cards participant reference.');
        }

        return cardsUser((int) $matches[1]);
    }
}

function cardsUser(int $id = 7): GenericUser
{
    // Numeric, faithful to the production participant columns (unsigned big integers) — a string
    // id would only ever pass on SQLite's permissiveness.
    return new GenericUser(['id' => $id]);
}

/** The one card's own element, DOM-extracted so its controls are judged and nothing else's. */
function cardsCardHtml(string $html, string $approvalId): string
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    foreach ((new DOMXPath($document))->query('//*[@data-approval-card="'.$approvalId.'"]') ?: [] as $node) {
        if ($node instanceof DOMElement) {
            return (string) $document->saveHTML($node);
        }
    }

    throw new LogicException('No rendered card found for approval ['.$approvalId.'].');
}

/** @return list<ApprovalVerb> the verbs THIS card offers, never another control's */
function cardsRenderedVerbs(string $html, string $approvalId): array
{
    preg_match_all('/data-verb="([^"]+)"/', cardsCardHtml($html, $approvalId), $matches);

    return array_map(fn (string $verb): ApprovalVerb => ApprovalVerb::from($verb), $matches[1]);
}

beforeEach(function (): void {
    $this->migrateChatTables();
    $this->app->instance(CardsLedger::class, new CardsLedger);
    $this->app->instance(ConversationParticipants::class, new CardsParticipants);

    // A permitting capability authorizer keeps these tests about the surface rather than policy
    // resolution: without one Verdict fails closed, the proposal is denied, and nothing pauses.
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('cards test');
        }
    });

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register(CARDS_ENTRY_KEY, fn (): CardsAgent => new CardsAgent, fn (Agent $agent): bool => $agent instanceof CardsAgent);

    config()->set('verdict-console.chat.entry_key', CARDS_ENTRY_KEY);
});

it('registers the chat component under the package namespace', function (): void {
    $component = Livewire::test('verdict-console-livewire::chat');

    $component->assertSeeHtml('data-verdict-console-livewire="chat"');

    expect($component->instance())->toBeInstanceOf(Chat::class, 'The alias must resolve THIS component, not an impostor.');
});

it('sends a prompt reactively and renders the reply in the same request cycle', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->textResponse('Your order is on its way.'))]);
    $this->actingAs(cardsUser());

    Livewire::test(Chat::class)
        ->set('prompt', 'Where is my order?')
        ->call('send')
        ->assertSet('prompt', '', 'The composer clears for the next message.')
        ->assertSet('conversation', fn (?string $id): bool => is_string($id) && $id !== '')
        ->assertSee('Where is my order?')
        ->assertSee('Your order is on its way.');

    Http::assertSentCount(1);
});

it('renders an approval card mid-thread when the run pauses', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(CARDS_TOOL_CALL_ID, 'CardsCancelOrderTool', ['order_id' => CARDS_ORDER_ID]))]);
    $this->actingAs(cardsUser());

    $component = Livewire::test(Chat::class)
        ->set('prompt', 'Please cancel order '.CARDS_ORDER_ID.'.')
        ->call('send');

    $row = StoredPendingApproval::query()->sole();

    $component->assertSeeHtml('data-approval-card="'.$row->id.'"')
        ->assertSeeHtml('data-verb="approve"')
        ->assertSeeHtml('data-verb="reject"')
        ->assertSee('Cancelling an order needs confirmation.');

    expect(app(CardsLedger::class)->executions)->toBe(0, 'Nothing executes before a human decides.');
});

it('approves the card in-flow and the resumed reply lands in the thread without a reload', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push($this->toolCallResponse(CARDS_TOOL_CALL_ID, 'CardsCancelOrderTool', ['order_id' => CARDS_ORDER_ID]))
        ->push($this->textResponse('Done — your order is cancelled.'))]);
    Gate::define('approve-verdict-action', fn (): bool => true);
    $this->actingAs(cardsUser());

    $component = Livewire::test(Chat::class)
        ->set('prompt', 'Please cancel order '.CARDS_ORDER_ID.'.')
        ->call('send');

    $row = StoredPendingApproval::query()->sole();

    $component->call('approve', (string) $row->id)
        ->assertSee('Done — your order is cancelled.')
        ->assertDontSeeHtml('data-approval-card');

    expect(app(CardsLedger::class)->executions)->toBe(1, 'The approved tool executes exactly once.')
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', CARDS_TOOL_CALL_ID)->value('status'))->toBe('consumed')
        // The core resolution service is the only writer of this operational counter: a component
        // driving ApprovalManager directly would leave it untouched.
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1);

    Http::assertSentCount(2);
});

it('rejects in-flow to a clean refusal without executing', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(CARDS_TOOL_CALL_ID, 'CardsCancelOrderTool', ['order_id' => CARDS_ORDER_ID]))]);
    Gate::define('approve-verdict-action', fn (): bool => true);
    $this->actingAs(cardsUser());

    $component = Livewire::test(Chat::class)
        ->set('prompt', 'Please cancel order '.CARDS_ORDER_ID.'.')
        ->call('send');

    $row = StoredPendingApproval::query()->sole();

    $component->call('reject', (string) $row->id)
        ->assertDontSeeHtml('data-approval-card');

    expect(app(CardsLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', CARDS_TOOL_CALL_ID)->value('status'))->toBe('rejected');

    // Measured on the core reject round trip: a bare rejection ends the turn without another
    // model call.
    Http::assertSentCount(1);
});

/**
 * ADR 0001's verb invariant, pinned the way every surface pins it: the buttons the card offers
 * are compared to the core resolver through ApprovalSurfaceContract, so this presentation layer
 * cannot grow an approve control of its own.
 */
it('renders exactly the verb set the surface contract resolves for the card', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(CARDS_TOOL_CALL_ID, 'CardsCancelOrderTool', ['order_id' => CARDS_ORDER_ID]))]);
    $this->actingAs(cardsUser());

    $component = Livewire::test(Chat::class)
        ->set('prompt', 'Please cancel order '.CARDS_ORDER_ID.'.')
        ->call('send');

    $row = StoredPendingApproval::query()->sole();
    $view = app(ApprovalStatusReader::class)->statusFor((string) $row->receipt_id);

    app(ApprovalSurfaceContract::class)->assertRendered(cardsRenderedVerbs($component->html(), (string) $row->id), $row, $view);

    expect(cardsRenderedVerbs($component->html(), (string) $row->id))->not->toBeEmpty();
});

/** Lifecycle stays the core's: an interrupt that is no longer pending stops being a card. */
it('shows no card for an interrupt that is no longer pending', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(CARDS_TOOL_CALL_ID, 'CardsCancelOrderTool', ['order_id' => CARDS_ORDER_ID]))]);
    $this->actingAs(cardsUser());

    $component = Livewire::test(Chat::class)
        ->set('prompt', 'Please cancel order '.CARDS_ORDER_ID.'.')
        ->call('send')
        ->assertSeeHtml('data-approval-card');

    DB::table('verdict_approval_receipts')->where('tool_call_id', CARDS_TOOL_CALL_ID)
        ->update(['status' => 'rejected', 'rejected_by' => 'someone-else', 'rejected_at' => now()]);

    $component->call('$refresh')
        ->assertDontSeeHtml('data-approval-card')
        ->assertSee('Please cancel order '.CARDS_ORDER_ID.'.');
});

/**
 * The core's indistinguishable refusal survives the reactive surface: a foreign conversation and
 * an unknown one both mount to the same 403. (Livewire keeps AuthorizationException handled, so
 * the component surfaces status, not exception; the exact-message parity is core-tested at
 * ChatService level and cannot silently diverge here.)
 */
it('refuses a foreign conversation exactly like an unknown one', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->textResponse('Hello.'))]);
    $this->actingAs(cardsUser());

    $started = Livewire::test(Chat::class)->set('prompt', 'Hi')->call('send');
    $conversation = $started->get('conversation');

    $this->actingAs(cardsUser(8));

    Livewire::test(Chat::class, ['conversation' => $conversation])->assertForbidden();
    Livewire::test(Chat::class, ['conversation' => 'no-such-conversation'])->assertForbidden();
});

/** Livewire keeps AuthorizationException handled (its request broker's except-list), so the refusal is a 403. */
it('refuses to send for a guest', function (): void {
    Http::fake();

    Livewire::test(Chat::class)
        ->set('prompt', 'Hi')
        ->call('send')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('validates a blank prompt as an input error, spending nothing', function (): void {
    Http::fake();
    $this->actingAs(cardsUser());

    Livewire::test(Chat::class)
        ->set('prompt', '   ')
        ->call('send')
        ->assertHasErrors(['prompt']);

    Http::assertNothingSent();
});

/** A mounted conversation continues; it must not fork a new thread per message. */
it('continues the mounted conversation across turns, keeping the history', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push($this->textResponse('First reply.'))
        ->push($this->textResponse('Second reply.'))]);
    $this->actingAs(cardsUser());

    $component = Livewire::test(Chat::class)
        ->set('prompt', 'First question')
        ->call('send');

    $conversation = $component->get('conversation');

    $component->set('prompt', 'Second question')
        ->call('send')
        ->assertSet('conversation', $conversation)
        ->assertSee('First question')
        ->assertSee('First reply.')
        ->assertSee('Second question')
        ->assertSee('Second reply.');

    Http::assertSentCount(2);
});

/**
 * The host Gate genuinely governs the card's decisions: the authenticated actor and the console
 * row reach the configured ability, a denial refuses without spending, and the card stays
 * actionable for someone who is allowed.
 */
it('refuses a decision the host Gate denies, leaving the card actionable', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(CARDS_TOOL_CALL_ID, 'CardsCancelOrderTool', ['order_id' => CARDS_ORDER_ID]))]);
    $consulted = [];
    Gate::define('approve-verdict-action', function (GenericUser $user, StoredPendingApproval $row) use (&$consulted): bool {
        $consulted[] = [$user->getAuthIdentifier(), $row->tool_call_id];

        return false;
    });
    $this->actingAs(cardsUser());

    $component = Livewire::test(Chat::class)
        ->set('prompt', 'Please cancel order '.CARDS_ORDER_ID.'.')
        ->call('send');

    $row = StoredPendingApproval::query()->sole();

    $component->call('approve', (string) $row->id)->assertForbidden();

    expect($consulted)->toBe([[7, CARDS_TOOL_CALL_ID]], 'The actor and the row must reach the configured ability.')
        ->and(app(CardsLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', CARDS_TOOL_CALL_ID)->value('status'))->toBe('pending');
});

/**
 * The component-side boundary the core scope cannot supply: a card belongs to its mounted
 * conversation. A row id from another thread — even the same operator's — answers not-found and
 * spends nothing, exactly like a row that does not exist.
 */
it('refuses to resolve a card that belongs to another conversation', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()
        ->push($this->toolCallResponse('call_cards_a', 'CardsCancelOrderTool', ['order_id' => CARDS_ORDER_ID]))
        ->push($this->toolCallResponse('call_cards_b', 'CardsCancelOrderTool', ['order_id' => CARDS_ORDER_ID]))]);
    Gate::define('approve-verdict-action', fn (): bool => true);
    $this->actingAs(cardsUser());

    Livewire::test(Chat::class)->set('prompt', 'Cancel order (thread A)')->call('send');
    $componentB = Livewire::test(Chat::class)->set('prompt', 'Cancel order (thread B)')->call('send');

    $rowA = StoredPendingApproval::query()->where('tool_call_id', 'call_cards_a')->sole();

    $componentB->call('approve', (string) $rowA->id)->assertNotFound();

    expect(app(CardsLedger::class)->executions)->toBe(0)
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_cards_a')->value('status'))->toBe('pending');
});

/**
 * The send path is falsifiably the core's: the entry key is ChatService territory, so an entry
 * pointing at an unregistered resolver must refuse the send — a component driving an agent of its
 * own would never notice.
 */
it('fails the send loudly when the configured entry key resolves no agent', function (): void {
    Http::fake();
    config()->set('verdict-console.chat.entry_key', 'nobody@v0');
    $this->actingAs(cardsUser());

    expect(fn () => Livewire::test(Chat::class)->set('prompt', 'Hi')->call('send'))
        ->toThrow(UnresolvableAgentKey::class);

    Http::assertNothingSent();
});

/**
 * The streaming seam this surface owns (design §8), pinned as the markup target only: Livewire's
 * test harness cannot observe streamed chunks, so this asserts the wire:stream seam is wired
 * before and after a send — not chunk timing. Token streaming from the model is core's #97.
 */
it('wires the stream target for the reply seam', function (): void {
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->textResponse('Streaming reply.'))]);
    $this->actingAs(cardsUser());

    Livewire::test(Chat::class)
        ->assertSeeHtml('wire:stream="reply"')
        ->set('prompt', 'Hi')
        ->call('send')
        ->assertSee('Streaming reply.')
        ->assertSeeHtml('wire:stream="reply"');
});

/** VC-23's transport decision, honored: poll on the dependency-free default, listen when broadcasting. */
it('polls only while the transport is polling, at the configured interval', function (): void {
    $this->actingAs(cardsUser());

    Livewire::test(Chat::class)->assertSeeHtml('wire:poll.5s');

    // The interval is the config's, not a constant that happens to match the default.
    config()->set('verdict-console-livewire.polling.interval_seconds', 9);

    Livewire::test(Chat::class)->assertSeeHtml('wire:poll.9s');

    config()->set('verdict-console-livewire.transport', 'broadcast');

    $html = Livewire::test(Chat::class)->html();

    expect($html)->not->toContain('wire:poll');
});
