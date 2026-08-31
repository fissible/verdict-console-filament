<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Pages;

use Filament\Pages\Page;
use Fissible\VerdictConsole\Configuration\ApprovalRules;
use Fissible\VerdictConsole\Configuration\CapabilityInspection as CapabilityInspectionItem;
use Fissible\VerdictConsole\Configuration\RateLimitInspection;
use Fissible\VerdictConsole\Contracts\ConfigurationInspection as ConfigurationInspectionContract;

/** Read-only declared configuration from the console's replaceable inspection boundary. */
final class ConfigurationInspection extends Page
{
    protected static ?string $title = 'Configuration inspection';

    protected string $view = 'verdict-console-filament::pages.configuration-inspection';

    /** @return array{capabilities: list<CapabilityInspectionItem>, rateLimits: list<RateLimitInspection>, approvalRules: ApprovalRules} */
    protected function getViewData(): array
    {
        $inspection = app(ConfigurationInspectionContract::class);

        return [
            'capabilities' => $inspection->capabilities(),
            'rateLimits' => $inspection->rateLimits(),
            'approvalRules' => $inspection->approvalRules(),
        ];
    }
}
