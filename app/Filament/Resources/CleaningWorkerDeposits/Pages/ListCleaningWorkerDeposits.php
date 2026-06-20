<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkerDeposits\Pages;

use App\Filament\Resources\CleaningWorkerDeposits\CleaningWorkerDepositsResource;
use App\Filament\Resources\CleaningWorkerDeposits\Tables\CleaningTransactionsTable;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Rap2hpoutre\FastExcel\FastExcel;

final class ListCleaningWorkerDeposits extends ListRecords
{
    protected static string $resource = CleaningWorkerDepositsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('cleaning_admin.transactions.actions.export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => (new FastExcel(CleaningTransactionsTable::exportRows()))
                    ->download('cleaning-transactions-'.now()->format('Y-m-d').'.xlsx')),
        ];
    }
}
