<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Fissible\Verdict\VerdictServiceProvider;
use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Fissible\VerdictConsoleFilament\VerdictConsoleFilamentServiceProvider;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
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
            // A real host gets every split Filament package registered by Laravel's package
            // discovery; Testbench runs no discovery, so the harness lists what discovery would.
            // These belong here, not in this package's service provider, which must never
            // re-register what the host already has.
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            // A host running this plugin runs the whole stack: Laravel AI and Verdict are what the
            // console's services resolve against, so booting them is fidelity, not convenience.
            AiServiceProvider::class,
            VerdictServiceProvider::class,
            VerdictConsoleServiceProvider::class,
            VerdictConsoleFilamentServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Filament renders encrypted state (and Livewire signs its snapshots), so the harness
        // needs a key the way any host app has one. Generated per run: a committed literal is
        // inert here, but indistinguishable from a leaked production key to secret scanners.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
