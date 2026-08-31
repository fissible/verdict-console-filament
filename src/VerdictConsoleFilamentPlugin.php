<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Fissible\VerdictConsoleFilament\Pages\ConfigurationInspection;
use Fissible\VerdictConsoleFilament\Pages\ConsoleDoctor;
use Fissible\VerdictConsoleFilament\Pages\EvidenceBrowser;
use Fissible\VerdictConsoleFilament\Pages\ExecutionClaims;
use Fissible\VerdictConsoleFilament\Resources\PendingApprovalResource;
use Fissible\VerdictConsoleFilament\Widgets\AnomalyAlarms;

/**
 * The plugin a host adds to an existing panel: `$panel->plugin(VerdictConsoleFilamentPlugin::make())`.
 *
 * It registers into the host's panel rather than shipping one of its own, because the operator
 * console belongs inside the host's admin surface and its authorization context -- a separate
 * panel would be a second place to configure guards and tenancy. Resources and ops pages join
 * here as the v0.1.0 milestone progresses; every one of them is an upgrade of a core Blade
 * surface, never the only implementation of it (core design §9).
 */
final class VerdictConsoleFilamentPlugin implements Plugin
{
    public static function make(): static
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return 'verdict-console';
    }

    public function register(Panel $panel): void
    {
        // The queue stays in the host panel so its authenticated operator and scope stay intact.
        $panel->resources([
            PendingApprovalResource::class,
        ]);

        $panel->pages([
            EvidenceBrowser::class,
            ExecutionClaims::class,
            ConsoleDoctor::class,
            ConfigurationInspection::class,
        ]);

        $panel->widgets([
            AnomalyAlarms::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
