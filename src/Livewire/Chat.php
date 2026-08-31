<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleLivewire\Livewire;

use Fissible\VerdictConsole\Approvals\ApprovalInbox;
use Fissible\VerdictConsole\Approvals\ApprovalItem;
use Fissible\VerdictConsole\Approvals\ApprovalResolutionService;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Chat\ChatService;
use Fissible\VerdictConsoleLivewire\Transport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Reactive adapter over the console core's participant-owned chat and approval services. */
final class Chat extends Component
{
    public ?string $conversation = null;

    public string $prompt = '';

    public function mount(?string $conversation = null): void
    {
        $this->conversation = $conversation;

        if ($conversation !== null && ! auth()->guest()) {
            /** @var Authenticatable $user */
            $user = auth()->user();

            app(ChatService::class)->thread($user, $conversation);
        }
    }

    public function send(): void
    {
        if (auth()->guest()) {
            throw new AuthorizationException('This participant may not use this conversation.');
        }

        $this->validate([
            'prompt' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (trim((string) $value) === '') {
                        $fail('The '.$attribute.' field must not be blank.');
                    }
                },
            ],
        ]);

        /** @var Authenticatable $user */
        $user = auth()->user();

        $chat = app(ChatService::class);
        $turn = $this->conversation === null
            ? $chat->start($user, $this->prompt)
            : $chat->continue($user, $this->conversation, $this->prompt);

        $this->stream(to: 'reply', content: $turn->text);

        $this->conversation = $turn->conversationId;
        $this->prompt = '';
    }

    public function approve(string $approvalId): void
    {
        $this->resolve($approvalId, true);
    }

    public function reject(string $approvalId): void
    {
        $this->resolve($approvalId, false);
    }

    public function render(): View
    {
        $thread = null;
        $cards = [];

        if (! auth()->guest() && $this->conversation !== null) {
            /** @var Authenticatable $user */
            $user = auth()->user();
            $thread = app(ChatService::class)->thread($user, $this->conversation);
            $cards = array_values(array_filter(
                app(ApprovalInbox::class)->itemsForConversation($this->conversation),
                static fn (ApprovalItem $item): bool => $item->state === 'pending',
            ));
        }

        return view('verdict-console-livewire::livewire.chat', [
            'thread' => $thread,
            'cards' => $cards,
            'polling' => Transport::fromConfig(config('verdict-console-livewire.transport')) === Transport::Polling,
            'interval' => (int) config('verdict-console-livewire.polling.interval_seconds'),
        ]);
    }

    private function resolve(string $approvalId, bool $approve): void
    {
        if (auth()->guest()) {
            throw new AuthorizationException('This approver may not resolve this approval.');
        }

        $approval = app(PendingApprovalStore::class)->findVisible($approvalId);

        if ($approval === null || $approval->conversation_id !== $this->conversation) {
            abort(404);
        }

        app(ApprovalResolutionService::class)->{$approve ? 'approve' : 'reject'}($approval, auth()->user());
    }
}
