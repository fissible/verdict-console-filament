<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Pages;

use Filament\Pages\Page;
use Fissible\VerdictConsole\Doctor\Doctor;
use Fissible\VerdictConsole\Doctor\Finding;

/** Read-only presentation of the core console preflight diagnostic. */
final class ConsoleDoctor extends Page
{
    protected static ?string $title = 'Console doctor';

    protected string $view = 'verdict-console-filament::pages.console-doctor';

    /** @return array{findings: list<Finding>} */
    protected function getViewData(): array
    {
        return ['findings' => app(Doctor::class)->run()];
    }
}
