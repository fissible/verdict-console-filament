<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Widgets;

use Filament\Widgets\Widget;
use Fissible\VerdictConsole\Incidents\Incident;
use Fissible\VerdictConsole\Incidents\IncidentStore;

/** A durable anomaly ledger; it reads observations and never records while rendering. */
final class AnomalyAlarms extends Widget
{
    protected string $view = 'verdict-console-filament::widgets.anomaly-alarms';

    /** @return array{incidents: list<Incident>} */
    protected function getViewData(): array
    {
        return ['incidents' => app(IncidentStore::class)->latest()];
    }
}
