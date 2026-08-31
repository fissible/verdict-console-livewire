<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleLivewire\Livewire;

use Fissible\VerdictConsole\Approvals\ApprovalInbox;
use Fissible\VerdictConsole\Approvals\ApprovalResolutionService;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Exceptions\ApprovalNotDrivable;
use Fissible\VerdictConsoleLivewire\Transport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Reactive adapter over the console core's operator approval inbox. */
final class Inbox extends Component
{
    /** The outcome of the last action, using the core controller's status vocabulary. */
    public string $status = '';

    public function approve(string $approvalId): void
    {
        $this->resolve($approvalId, true);
    }

    public function reject(string $approvalId): void
    {
        $this->resolve($approvalId, false);
    }

    public function close(string $approvalId): void
    {
        $approval = $this->approval($approvalId);

        try {
            $this->status = app(ApprovalResolutionService::class)->close($approval, $this->approver())->value;
        } catch (ApprovalNotDrivable) {
            $this->status = 'not_actionable';
        }
    }

    public function render(): View
    {
        return view('verdict-console-livewire::livewire.inbox', [
            'items' => app(ApprovalInbox::class)->items(),
            'polling' => Transport::fromConfig(config('verdict-console-livewire.transport')) === Transport::Polling,
            'interval' => (int) config('verdict-console-livewire.polling.interval_seconds'),
        ]);
    }

    private function resolve(string $approvalId, bool $approve): void
    {
        $approval = $this->approval($approvalId);

        try {
            $transition = $approve
                ? app(ApprovalResolutionService::class)->approve($approval, $this->approver())
                : app(ApprovalResolutionService::class)->reject($approval, $this->approver());

            $this->status = $transition === null
                ? 'not_actionable'
                : ($approve ? 'approved' : 'rejected');
        } catch (ApprovalNotDrivable) {
            $this->status = 'not_actionable';
        }
    }

    private function approval(string $approvalId): PendingApproval
    {
        if (auth()->guest()) {
            throw new AuthorizationException('This approver may not resolve this approval.');
        }

        $approval = app(PendingApprovalStore::class)->findVisible($approvalId);

        abort_if($approval === null, 404);

        return $approval;
    }

    private function approver(): Authenticatable
    {
        /** @var Authenticatable $user */
        $user = auth()->user();

        return $user;
    }
}
