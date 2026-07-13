<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Pages;

use App\Filament\Resources\CleaningWorkerDeposits\CleaningWorkerDepositsResource;
use App\Filament\Resources\CleaningWorkerDeposits\Tables\CleaningTransactionsTable;
use App\Filament\Resources\CleaningWorkerDeposits\Widgets\CleaningWorkerDepositStats;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                ->url(CleaningWorkerDepositsResource::getUrl('create')),
            Action::make('export')
                ->label(__('cleaning_admin.transactions.actions.export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => (new FastExcel(CleaningTransactionsTable::exportRows($this->getTableQueryForExport())))
                    ->download('cleaning-transactions-'.now()->format('Y-m-d').'.xlsx')),
        ];
    }
}
