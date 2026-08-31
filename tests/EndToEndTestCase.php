<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleLivewire\Tests;

use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\VerdictServiceProvider;
use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Fissible\VerdictConsoleLivewire\VerdictConsoleLivewireServiceProvider;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The heavy base case for this adapter: Livewire, Laravel AI, Verdict, the console core, and this
 * package, all real. The flagship surface has no meaning with less — the pause comes from Laravel
 * AI, the receipt from Verdict, the thread and verbs from the core, and the reactivity from
 * Livewire. Hermetic like the core's equivalent: the provider only ever answers through
 * Http::fake().
 */
abstract class EndToEndTestCase extends Orchestra
{
    public const string PROVIDER = 'livewire_e2e';

    public const string MODEL = 'livewire-e2e-model';

    public const string BASE_URL = 'https://openai-compatible.invalid/v1';

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            AiServiceProvider::class,
            VerdictServiceProvider::class,
            VerdictConsoleServiceProvider::class,
            VerdictConsoleLivewireServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.'.self::PROVIDER, [
            'driver' => 'openai_compatible',
            'key' => 'not-a-real-key',
            'url' => self::BASE_URL,
            'models' => ['text' => ['default' => self::MODEL]],
        ]);

        $app['config']->set('ai.conversations.generate_title', false);

        // Verdict fails closed without a host authorizer; test-only wiring, exactly as the core's
        // end-to-end suite does it.
        $app['config']->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);

        // Livewire component requests need a key the way any session-backed request does.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    /** Create every table a paused-and-resumed chat writes through the real stack. */
    protected function migrateChatTables(): void
    {
        $verdict = dirname(__DIR__).'/vendor/fissible/verdict/database/migrations';
        $console = dirname(__DIR__).'/vendor/fissible/verdict-console/database/migrations';
        $ai = dirname(__DIR__).'/vendor/laravel/ai/database/migrations';

        (require $verdict.'/create_verdict_approval_receipts_table.php.stub')->up();
        (require $verdict.'/add_proposal_provenance_to_verdict_approval_receipts_table.php.stub')->up();
        (require $verdict.'/add_approval_context_to_verdict_approval_receipts_table.php.stub')->up();
        (require $ai.'/2026_01_11_000001_create_agent_conversations_table.php')->up();
        (require $console.'/create_verdict_console_pending_approvals_table.php.stub')->up();
        (require $console.'/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
        (require $console.'/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
        (require $console.'/create_verdict_console_approval_notifications_table.php.stub')->up();
        (require $console.'/create_verdict_console_approval_reconciliations_table.php.stub')->up();
        (require $console.'/create_verdict_console_incidents_table.php.stub')->up();
        (require $console.'/create_verdict_console_conversation_invocations_table.php.stub')->up();
    }

    /**
     * A chat-completions body carrying a single tool call.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function toolCallResponse(string $toolCallId, string $toolName, array $arguments): array
    {
        return [
            'model' => self::MODEL,
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => $toolCallId,
                        'type' => 'function',
                        'function' => ['name' => $toolName, 'arguments' => json_encode($arguments)],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
    }

    /**
     * A chat-completions body carrying a final assistant message.
     *
     * @return array<string, mixed>
     */
    protected function textResponse(string $text): array
    {
        return [
            'model' => self::MODEL,
            'choices' => [[
                'message' => ['content' => $text],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
    }
}
