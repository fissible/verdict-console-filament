<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the Filament adapter into a host that already runs the console core.
 *
 * Deliberately thin: the unit of integration is the panel plugin, which the host attaches to its
 * own panel provider. Nothing here duplicates or overrides a core binding; the heavy Filament
 * dependency stays correctly isolated in this package (core design §9).
 */
final class VerdictConsoleFilamentServiceProvider extends ServiceProvider {}
