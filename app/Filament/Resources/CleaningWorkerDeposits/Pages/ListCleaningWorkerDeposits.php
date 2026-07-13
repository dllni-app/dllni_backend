<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkerDeposits\CleaningWorkerDepositsResource;
use App\Filament\Resources\CleaningWorkerDeposits\Tables\CleaningTransactionsTable;
use App\Filament\Resources\CleaningWorkerDeposits\Widgets\CleaningWorkerDepositStats;
use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerDebtService;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ListCleaningWorkerDeposits extends ListRecords
{
    protected static string $resource = CleaningWorkerDepositsResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('cleaning_finance_guidance.transactions_page_subtitle');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CleaningWorkerDepositStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createTransaction')
                ->label(__('cleaning_admin.transactions.actions.create'))
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->form([
                    Select::make('worker_id')
                        ->label(__('cleaning_admin.transactions.fields.worker'))
                        ->options(fn (): array => self::workerOptions())
                        ->searchable()
                        ->required(),
                    Select::make('type')
                        ->label(__('cleaning_admin.transactions.fields.type'))
                        ->placeholder(__('cleaning_finance_guidance.placeholders.type'))
                        ->helperText(__('cleaning_finance_guidance.select_helper'))
                        ->options([
                            'deposit' => __('cleaning_admin.transactions.types.deposit'),
                            'debt' => __('cleaning_finance.types.debt'),
                            'settlement' => __('cleaning_admin.transactions.types.settlement'),
                            'refund' => __('cleaning_admin.transactions.types.refund'),
                        ])
                        ->default('deposit')
                        ->required(),
                    Placeholder::make('transaction_type_guide')
                        ->label(__('cleaning_finance_guidance.title'))
                        ->content(__('cleaning_finance_guidance.compact'))
                        ->columnSpanFull(),
                    TextInput::make('amount')
                        ->label(__('cleaning_admin.transactions.fields.amount'))
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->helperText(__('cleaning_finance.fields.positive_amount_hint')),
                    Textarea::make('notes')
                        ->label(__('cleaning_admin.transactions.fields.notes'))
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $worker = Worker::query()->find($data['worker_id']);

                    if (! $worker instanceof Worker) {
                        Notification::make()->title(__('cleaning_admin.transactions.actions.error'))->danger()->send();

                        return;
                    }

                    $depositService = app(DepositService::class);
                    $debtService = app(WorkerDebtService::class);
                    $amount = (float) $data['amount'];
                    $notes = isset($data['notes']) && mb_trim((string) $data['notes']) !== '' ? mb_trim((string) $data['notes']) : null;

                    try {
                        match ($data['type']) {
                            'deposit' => $depositService->recordDeposit($worker, $amount, 'admin_manual', $notes, auth()->id()),
                            'debt' => $debtService->recordDebt($worker, $amount, 'admin_manual_debt', $notes, auth()->id()),
                            'settlement' => $debtService->recordSettlement($worker, $amount, 'admin_manual', $notes, auth()->id()),
                            'refund' => $depositService->recordRefund($worker, $amount, 'admin_manual', $notes, auth()->id()),
                            default => null,
                        };

                        Notification::make()->title(__('cleaning_admin.transactions.actions.create_success'))->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title(__('cleaning_admin.transactions.actions.error'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('export')
                ->label(__('cleaning_admin.transactions.actions.export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => (new FastExcel(CleaningTransactionsTable::exportRows($this->getTableQueryForExport())))
                    ->download('cleaning-transactions-'.now()->format('Y-m-d').'.xlsx')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function workerOptions(): array
    {
        return Worker::query()
            ->whereHas('user', fn (Builder $query) => $query->where('module_type', UserModuleType::CleaningWorker))
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Worker $worker): array => [
                $worker->id => ($worker->first_name ?: ('#'.$worker->id)).' (#'.$worker->id.')',
            ])
            ->all();
    }
}
