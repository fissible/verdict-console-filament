<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Resources\PendingApprovalResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Resources\Pages\ListRecords;
use Fissible\VerdictConsole\Approvals\ApprovalItem;
use Fissible\VerdictConsole\Approvals\ApprovalItemFactory;
use Fissible\VerdictConsole\Approvals\ApprovalVerb;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsoleFilament\Resources\PendingApprovalResource;
use Livewire\Attributes\Locked;

/**
 * Lists the host-visible queue without adding resource mutations, because decisions stay the
 * console's explicit approval verbs rather than generic record edits.
 */
final class ListPendingApprovals extends ListRecords
{
    protected static string $resource = PendingApprovalResource::class;

    /** @var array<string, ApprovalItem> */
    private array $approvalItems = [];

    private bool $forgetApprovalVerbsOnDehydrate = false;

    /** @var array<string, list<string>> */
    private array $clickedApprovalVerbs = [];

    /** @var array<string, list<string>> */
    #[Locked]
    public array $approvalVerbs = [];

    /**
     * Keep the item an operator saw through their click; the resolution service then performs the
     * authoritative fresh read, so a lapsed decision is reported instead of vanishing from the UI.
     */
    /** @return list<ApprovalVerb> */
    public function verbs(PendingApproval $record): array
    {
        $key = (string) $record->getKey();
        $values = $this->mountedTableActionRecordKey() === $key
            ? $this->clickedApprovalVerbs[$key] ?? null
            : null;
        $values ??= $this->approvalVerbs[$key] ??= array_map(
            static fn (ApprovalVerb $verb): string => $verb->value,
            $this->item($record)->verbs,
        );

        return array_map(ApprovalVerb::from(...), $values);
    }

    /** Return the single approval item used by every rendering surface for this row. */
    public function item(PendingApproval $record): ApprovalItem
    {
        return $this->approvalItems[(string) $record->getKey()] ??= app(ApprovalItemFactory::class)->make($record);
    }

    /** Forget a pre-action item and verbs so the action response renders fresh receipt state. */
    public function forget(PendingApproval $record): void
    {
        $key = (string) $record->getKey();

        unset($this->approvalItems[$key], $this->approvalVerbs[$key]);
        unset($this->clickedApprovalVerbs[$key]);
        $this->forgetApprovalVerbsOnDehydrate = true;
    }

    /**
     * Start every ordinary request with fresh verbs, but retain the rendered set for the table row
     * whose action is mounting so a decision that lapsed after the click can report that outcome.
     */
    public function hydrate(): void
    {
        $mountedRecordKey = $this->mountedTableActionRecordKey();
        $this->clickedApprovalVerbs = $mountedRecordKey === null
            ? $this->approvalVerbs
            : array_intersect_key($this->approvalVerbs, [$mountedRecordKey => true]);
        $this->approvalVerbs = [];
    }

    /** Drop request-local items and verb sets after every response. */
    public function dehydrate(): void
    {
        $this->approvalItems = [];
        $this->clickedApprovalVerbs = [];

        if ($this->forgetApprovalVerbsOnDehydrate) {
            $this->approvalVerbs = [];
        }
    }

    private function mountedTableActionRecordKey(): ?string
    {
        foreach (array_reverse($this->mountedActions ?? []) as $action) {
            $context = $action['context'] ?? [];

            if (($context['table'] ?? false) && array_key_exists('recordKey', $context)) {
                return (string) $context['recordKey'];
            }
        }

        return null;
    }

    /**
     * Filament falls back to row actions when resolving a bulk-addressed request. Refuse that
     * production request path so an approval verb cannot bypass its per-row scope check.
     *
     * @param  string|array<array{name: string, context?: array{bulk?: bool}}>  $actions
     */
    #[\Override]
    public function getAction(string|array $actions, bool $isMounting = true): ?Action
    {
        foreach ((array) $actions as $action) {
            if (is_array($action) && ($action['context']['bulk'] ?? false)) {
                throw new ActionNotResolvableException('Approval queue bulk actions are not available.');
            }
        }

        return parent::getAction($actions, $isMounting);
    }
}
