<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\ApprovalVerbs;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsoleLivewire\Transport;
use Fissible\VerdictConsoleLivewire\VerdictConsoleLivewireServiceProvider;
use Livewire\LivewireServiceProvider;

it('boots beside Livewire and the console core, as a clean install would', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(VerdictConsoleLivewireServiceProvider::class)
        ->toHaveKey(LivewireServiceProvider::class);

    // The headless contracts this adapter's surfaces render through resolve from core.
    expect(app(ApprovalVerbs::class))->toBeInstanceOf(ApprovalVerbs::class)
        ->and(app()->bound(ApprovalScope::class))->toBeTrue();
});

it('defaults to the polling transport, needing no infrastructure', function (): void {
    expect(config('verdict-console-livewire.transport'))->toBe('polling')
        ->and(config('verdict-console-livewire.polling.interval_seconds'))->toBe(5)
        ->and(Transport::fromConfig(config('verdict-console-livewire.transport')))->toBe(Transport::Polling);
});

it('opts into broadcast through configuration alone', function (): void {
    config()->set('verdict-console-livewire.transport', 'broadcast');

    expect(Transport::fromConfig(config('verdict-console-livewire.transport')))->toBe(Transport::Broadcast);
});

/** A dead realtime pipeline must be loud: an unknown transport is refused, never coerced to polling. */
it('refuses an unknown transport instead of silently falling back', function (): void {
    expect(fn (): Transport => Transport::fromConfig('websocket'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Transport => Transport::fromConfig(null))->toThrow(InvalidArgumentException::class);
});

it('publishes its configuration under its own tag', function (): void {
    $paths = VerdictConsoleLivewireServiceProvider::pathsToPublish(
        VerdictConsoleLivewireServiceProvider::class,
        'verdict-console-livewire-config',
    );

    expect($paths)->not->toBeEmpty();
});
