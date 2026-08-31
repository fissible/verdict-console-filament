<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Tests;

use Filament\Panel;
use Filament\PanelProvider;
use Fissible\VerdictConsoleFilament\VerdictConsoleFilamentPlugin;

/** The host-panel stand-in the plugin registers into, exactly as a real admin panel would. */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('testing')
            ->path('testing')
            ->default()
            ->plugin(VerdictConsoleFilamentPlugin::make());
    }
}
