<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleLivewire\Tests;

use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Fissible\VerdictConsoleLivewire\VerdictConsoleLivewireServiceProvider;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the full install a clean host app would have: Livewire, the console core, and this
 * adapter. VC-23's acceptance is exactly that this trio boots together.
 *
 * @property Application $app
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            VerdictConsoleServiceProvider::class,
            VerdictConsoleLivewireServiceProvider::class,
        ];
    }

    /**
     * Livewire component requests are session-backed and need an app key like any other request;
     * a fixed test-only key keeps the suite hermetic and deterministic (the EndToEnd base does
     * the same).
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}
