<?php

declare(strict_types=1);

namespace Fissible\VerdictConsoleFilament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Fissible\VerdictConsole\Exceptions\ExecutionClaimStillActive;
use Fissible\VerdictConsole\ExecutionClaims\ExecutionClaimItem;
use Fissible\VerdictConsole\ExecutionClaims\ExecutionClaimService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/** The human reconciliation queue, driven entirely by the console claim boundary. */
final class ExecutionClaims extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Execution claims';

    protected string $view = 'verdict-console-filament::pages.execution-claims';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (int $page, int|string $recordsPerPage): LengthAwarePaginator {
                $records = [];

                foreach (app(ExecutionClaimService::class)->unresolved() as $item) {
                    $records[$item->id] = self::record($item);
                }

                $perPage = $recordsPerPage === 'all' ? max(count($records), 1) : (int) $recordsPerPage;

                return new LengthAwarePaginator(
                    array_slice($records, ($page - 1) * $perPage, $perPage, preserve_keys: true),
                    count($records),
                    $perPage,
                    $page,
                );
            })
            ->columns([
                TextColumn::make('id')->label('ID'),
                TextColumn::make('capability'),
                TextColumn::make('policy'),
                TextColumn::make('status'),
                TextColumn::make('attempt_count')->label('Attempts'),
                TextColumn::make('fingerprint')->label('Fingerprint'),
                TextColumn::make('updated_at')->label('Updated'),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->modalHeading('Resolve execution claim')
                    ->schema([
                        Select::make('resolution')
                            ->options([
                                ExecutionClaimResolution::Completed->value => 'Completed',
                                ExecutionClaimResolution::Retryable->value => 'Retryable',
                            ])
                            ->required(),
                        TextInput::make('reason')->required(),
                    ])
                    ->action(function (array $record, array $data): void {
                        try {
                            app(ExecutionClaimService::class)->resolve(
                                $record['id'],
                                ExecutionClaimResolution::from($data['resolution']),
                                Auth::user(),
                                $data['reason'],
                            );

                            $title = 'Execution claim resolved';
                        } catch (ExecutionClaimStillActive $exception) {
                            $title = $exception->getMessage();
                        }

                        Notification::make()->title($title)->send();
                    }),
            ])
            ->emptyStateHeading('No unresolved Verdict execution claims.');
    }

    /** @return array<string, int|string> */
    private static function record(ExecutionClaimItem $item): array
    {
        return [
            'id' => $item->id,
            'fingerprint' => $item->fingerprint,
            'capability' => $item->capability,
            'policy' => $item->policy,
            'status' => $item->status,
            'attempt_count' => $item->attemptCount,
            'updated_at' => $item->updatedAt->format(DATE_ATOM),
        ];
    }
}
