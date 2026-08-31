<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleLivewire;

use InvalidArgumentException;

/**
 * The two ways a Livewire surface may learn that server state changed.
 *
 * Polling is the dependency-free default; broadcast is the host's opt-in upgrade (Reverb/Pusher).
 * The set is closed and misconfiguration is refused loudly: a surface silently falling back to
 * polling when a host believed it was broadcasting would hide a dead realtime pipeline, and one
 * silently broadcasting would surprise a host that provisioned nothing.
 */
enum Transport: string
{
    case Polling = 'polling';
    case Broadcast = 'broadcast';

    public static function fromConfig(mixed $value): self
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('verdict-console-livewire.transport must be a string.');
        }

        return self::tryFrom($value) ?? throw new InvalidArgumentException(
            'verdict-console-livewire.transport must be "polling" or "broadcast", got "'.$value.'".'
        );
    }
}
