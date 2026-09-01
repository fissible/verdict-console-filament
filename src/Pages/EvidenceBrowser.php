<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Fissible\Verdict\Decisions\Disposition;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidencePage;
use Fissible\VerdictConsole\Evidence\EvidenceRecord;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read-only evidence projection over the console's replaceable read boundary.
 *
 * The table deliberately uses custom data: evidence records are display DTOs, not Eloquent models,
 * and resolving the boundary while records render keeps every Livewire request current for the host.
 */
final class EvidenceBrowser extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Evidence browser';

    protected string $view = 'verdict-console-filament::pages.evidence-browser';

    private EvidenceRecordingState $recording = EvidenceRecordingState::On;

    private ?string $recordedBy = null;

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters, int $page, int|string $recordsPerPage): LengthAwarePaginator {
                $perPage = $recordsPerPage === 'all' ? PHP_INT_MAX : (int) $recordsPerPage;

                $result = app(EvidenceQuery::class)->searchPage(new EvidenceFilter(
                    disposition: $filters['disposition']['value'] ?? null,
                    capability: $filters['capability']['value'] ?? null,
                ), $page, $perPage);

                $this->recording = $result->recording;
                $this->recordedBy = $result->recordedBy;

                return new LengthAwarePaginator(
                    self::records($result),
                    $result->total,
                    $result->perPage,
                    $result->page,
                );
            })
            ->columns([
                TextColumn::make('recorded_at')->label('Recorded at'),
                TextColumn::make('capability'),
                TextColumn::make('stage'),
                TextColumn::make('disposition'),
                TextColumn::make('claim_type')->label('Claim type'),
                TextColumn::make('record_digest')->label('Record digest'),
                TextColumn::make('invocation_id')->label('Invocation ID'),
                TextColumn::make('argument_fingerprint')->label('Argument fingerprint'),
                TextColumn::make('actor_fingerprint')->label('Actor fingerprint'),
                TextColumn::make('approval_receipt_fingerprint')->label('Approval receipt fingerprint'),
                TextColumn::make('configuration_fingerprint')->label('Configuration fingerprint'),
                TextColumn::make('rate_limit_key_fingerprint')->label('Rate limit key fingerprint'),
            ])
            ->filters([
                SelectFilter::make('disposition')->options(self::dispositionOptions()),
                SelectFilter::make('capability')->schema([
                    TextInput::make('value')->label('Capability'),
                ]),
            ])
            ->recordActions([
                Action::make('view')
                    ->modalSubmitAction(false)
                    ->modalContent(static fn (array $record): View => view(
                        'verdict-console-filament::pages.evidence-browser-detail',
                        ['record' => $record],
                    )),
            ])
            ->emptyStateHeading(fn (): string => match ($this->recording) {
                EvidenceRecordingState::Off => 'recording is off — blank by config.',
                EvidenceRecordingState::Chained => 'A chained sink'.($this->recordedBy === null ? '' : " ({$this->recordedBy})").' is configured; decisions are not readable from this table.',
                EvidenceRecordingState::Elsewhere => "Evidence is recorded elsewhere by {$this->recordedBy}.",
                EvidenceRecordingState::On => 'No decisions have been recorded.',
            });
    }

    /** @return array<string, string|null> */
    private static function record(EvidenceRecord $record): array
    {
        return [
            'capability' => $record->capability,
            'stage' => $record->stage,
            'disposition' => $record->disposition,
            'claim_type' => $record->claimType,
            'record_digest' => $record->recordDigest,
            'argument_fingerprint' => $record->argumentFingerprint,
            'idempotency_key_fingerprint' => $record->idempotencyKeyFingerprint,
            'approval_receipt_fingerprint' => $record->approvalReceiptFingerprint,
            'configuration_fingerprint' => $record->configurationFingerprint,
            'actor_fingerprint' => $record->actorFingerprint,
            'subject_fingerprint' => $record->subjectFingerprint,
            'proposal_target_identity_fingerprint' => $record->proposalTargetIdentityFingerprint,
            'execution_target_identity_fingerprint' => $record->executionTargetIdentityFingerprint,
            'rate_limit_key_fingerprint' => $record->rateLimitKeyFingerprint,
            'execution_claim_fingerprint' => $record->executionClaimFingerprint,
            'execution_claim_binding_fingerprint' => $record->executionClaimBindingFingerprint,
            'invocation_id' => $record->invocationId,
            'rate_limit_reset_at' => $record->rateLimitResetAt?->format(DATE_ATOM),
            'recorded_at' => $record->recordedAt->format(DATE_ATOM),
        ];
    }

    /** @return array<string, array<string, string|null>> */
    private static function records(EvidencePage $page): array
    {
        $records = [];

        foreach ($page->records as $record) {
            $records[$record->id] = self::record($record);
        }

        return $records;
    }

    /** @return array<string, string> */
    private static function dispositionOptions(): array
    {
        $options = [];

        foreach (Disposition::cases() as $disposition) {
            $options[$disposition->value] = ucwords(str_replace('_', ' ', $disposition->value));
        }

        return $options;
    }
}
