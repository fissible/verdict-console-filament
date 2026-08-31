<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Tests;

use Filament\FilamentServiceProvider;
use Filament\Support\SupportServiceProvider;
use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Fissible\VerdictConsoleFilament\VerdictConsoleFilamentServiceProvider;
use Illuminate\Foundation\Application;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the install a host admin app would have: Filament, the console core, this adapter, and a
 * test panel (registered by {@see TestPanelProvider}) for the plugin to register into. VC-27's
 * acceptance is exactly that this registration works.
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
            SupportServiceProvider::class,
            FilamentServiceProvider::class,
            VerdictConsoleServiceProvider::class,
            VerdictConsoleFilamentServiceProvider::class,
            TestPanelProvider::class,
        ];
    }
}
