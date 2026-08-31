<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Concerns;

use Illuminate\Support\MessageBag;

/**
 * Keeps evidence-bounded Filament pages writable when Testbench renders a table schema without
 * Livewire having initialized its normal error bag. Production Livewire bags are preserved.
 */
trait ProvidesTestbenchErrorBag
{
    public function getErrorBag(): MessageBag
    {
        $bag = parent::getErrorBag();

        if ($bag instanceof MessageBag) {
            return $bag;
        }

        $bag = new MessageBag;
        parent::setErrorBag($bag);

        return $bag;
    }
}
