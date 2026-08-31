<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Fissible\VerdictConsole\Approvals\ApprovalVerbs;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsoleFilament\VerdictConsoleFilamentPlugin;
use Fissible\VerdictConsoleFilament\VerdictConsoleFilamentServiceProvider;

it('boots beside Filament and the console core, as a host admin app would', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(VerdictConsoleFilamentServiceProvider::class);

    // The headless contracts the plugin's Resources render through resolve from core.
    expect(app(ApprovalVerbs::class))->toBeInstanceOf(ApprovalVerbs::class)
        ->and(app()->bound(ApprovalScope::class))->toBeTrue();
});

it('registers the plugin into an existing panel', function (): void {
    Filament::setCurrentPanel('testing');
    $panel = Filament::getCurrentPanel();

    expect($panel)->not->toBeNull()
        ->and($panel->getPlugin('verdict-console'))->toBeInstanceOf(VerdictConsoleFilamentPlugin::class);
});

it('claims a stable plugin id a host can look up', function (): void {
    expect(VerdictConsoleFilamentPlugin::make()->getId())->toBe('verdict-console');
});
