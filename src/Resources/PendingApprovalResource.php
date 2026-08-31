<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Resources;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\VerdictConsole\Approvals\ApprovalResolutionService;
use Fissible\VerdictConsole\Approvals\ApprovalVerb;
use Fissible\VerdictConsole\Approvals\CloseOutcome;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsole\Exceptions\ApprovalNotDrivable;
use Fissible\VerdictConsoleFilament\Resources\PendingApprovalResource\Pages\ListPendingApprovals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The host-scoped operator queue over the console's render-time approval contracts.
 *
 * It deliberately owns no approval policy: item state and offered verbs come from the core factory,
 * and each click relays to its resolution service, so this surface cannot fork receipt semantics.
 *
 * @extends resource<PendingApproval>
 */
final class PendingApprovalResource extends Resource
{
    protected static ?string $model = PendingApproval::class;

    public static function getEloquentQuery(): Builder
    {
        // Match PendingApprovalStore::visible(): host scope first, then its stable queue ordering.
        return app(ApprovalScope::class)->apply(PendingApproval::query())
            ->orderByDesc('created_at')
            ->orderBy('id');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tool')
                    ->getStateUsing(static fn (PendingApproval $record, ListPendingApprovals $livewire): ?string => $livewire->item($record)->presentation['tool'] ?? null),
                TextColumn::make('state')
                    ->getStateUsing(static fn (PendingApproval $record, ListPendingApprovals $livewire): string => $livewire->item($record)->state),
                TextColumn::make('resumability')
                    ->getStateUsing(static fn (PendingApproval $record): string => $record->resumability->value),
                TextColumn::make('unresumable_reason')
                    ->getStateUsing(static fn (PendingApproval $record): ?string => $record->unresumable_reason?->value),
            ])
            ->filters([
                SelectFilter::make('resumability')
                    ->options([
                        Resumability::Drivable->value => 'Drivable',
                        Resumability::Unresumable->value => 'Unresumable',
                    ]),
            ])
            ->actions([
                self::resolutionAction(ApprovalVerb::Approve),
                self::resolutionAction(ApprovalVerb::Reject),
                self::resolutionAction(ApprovalVerb::Close),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListPendingApprovals::route('/'),
        ];
    }

    private static function resolutionAction(ApprovalVerb $verb): Action
    {
        return Action::make($verb->value)
            ->visible(static fn (PendingApproval $record, ListPendingApprovals $livewire): bool => in_array($verb, $livewire->verbs($record), true))
            ->action(static function (PendingApproval $record, ListPendingApprovals $livewire) use ($verb): void {
                try {
                    $outcome = match ($verb) {
                        ApprovalVerb::Approve => app(ApprovalResolutionService::class)->approve($record, Auth::user()),
                        ApprovalVerb::Reject => app(ApprovalResolutionService::class)->reject($record, Auth::user()),
                        ApprovalVerb::Close => app(ApprovalResolutionService::class)->close($record, Auth::user()),
                    };

                    $title = self::notificationTitle($outcome);
                } catch (ApprovalNotDrivable) {
                    // A stale render can reach a row that later loses its resumability; report it like
                    // every other handled outcome without swallowing authorization failures.
                    $title = 'No longer actionable';
                } finally {
                    $livewire->forget($record);
                }

                Notification::make()
                    ->title($title)
                    ->send();
            });
    }

    private static function notificationTitle(ApprovalTransition|CloseOutcome|null $outcome): string
    {
        return match (true) {
            $outcome instanceof ApprovalTransition && $outcome->outcome === ApprovalOutcome::Approved => 'Approved',
            $outcome instanceof ApprovalTransition && $outcome->outcome === ApprovalOutcome::Rejected => 'Rejected',
            $outcome === CloseOutcome::Closed => 'Closed',
            default => 'No longer actionable',
        };
    }
}
