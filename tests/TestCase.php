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
}
