<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleLivewire;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Wires the Livewire adapter into a host that already runs the console core.
 *
 * This package is an upgrade layer, never the baseline (core design §9): core's Blade components
 * remain the complete surface set, and everything registered here renders through the same
 * headless contracts (ApprovalVerbs, the approval item read-model, the evidence query). The
 * provider therefore adds configuration and, as the v0.1.0 milestone progresses, components -- it
 * duplicates no core binding and overrides none.
 */
final class VerdictConsoleLivewireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verdict-console-livewire.php', 'verdict-console-livewire');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'verdict-console-livewire');

        Livewire::addNamespace(
            namespace: 'verdict-console-livewire',
            classNamespace: 'Fissible\\VerdictConsoleLivewire\\Livewire',
            classPath: __DIR__.'/Livewire',
            classViewPath: __DIR__.'/../resources/views/livewire',
        );

        $this->publishes([
            __DIR__.'/../config/verdict-console-livewire.php' => config_path('verdict-console-livewire.php'),
        ], ['verdict-console-livewire', 'verdict-console-livewire-config']);
    }
}
