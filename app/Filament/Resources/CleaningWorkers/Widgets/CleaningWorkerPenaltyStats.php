<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Widgets;

use App\Filament\Resources\CleaningFinancialPenalties\CleaningFinancialPenaltyResource;
use App\Filament\Support\AdminUiFormatter;
use App\Models\CleaningFinancialPenalty;
use App\Models\Worker;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

final class CleaningWorkerPenaltyStats extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $worker = $this->record;
        if (! $worker instanceof Worker) {
            return [];
        }

        $activeAmount = (float) CleaningFinancialPenalty::query()
            ->where('worker_id', $worker->id)
            ->active()
            ->sum('amount');
        $activeCount = CleaningFinancialPenalty::query()
            ->where('worker_id', $worker->id)
            ->active()
            ->count();

        return [
            Stat::make('قيمة الغرامات المالية', AdminUiFormatter::formatCurrency($activeAmount))
                ->description($activeCount > 0 ? "عدد الغرامات النشطة: {$activeCount}" : 'لا توجد غرامات مالية نشطة')
                ->descriptionIcon($activeCount > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                ->icon('heroicon-o-banknotes')
                ->color($activeAmount > 0 ? 'danger' : 'success')
                ->url(CleaningFinancialPenaltyResource::getUrl('index', [
                    'tableFilters' => [
                        'worker_id' => ['value' => $worker->id],
                        'status' => ['value' => CleaningFinancialPenalty::STATUS_ACTIVE],
                    ],
                ])),
        ];
    }

    protected function getColumns(): int
    {
        return 1;
    }
}
