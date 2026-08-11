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

    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $worker = $this->record;
        $amount = $worker instanceof Worker
            ? (float) CleaningFinancialPenalty::query()
                ->where('worker_id', $worker->id)
                ->where('status', CleaningFinancialPenalty::STATUS_ACTIVE)
                ->sum('amount')
            : 0.0;

        return [
            Stat::make('قيمة الغرامات المالية', AdminUiFormatter::formatCurrency($amount))
                ->description($amount > 0 ? 'الغرامات الفعالة المسجلة على العامل' : 'لا توجد غرامات مالية فعالة')
                ->icon('heroicon-o-banknotes')
                ->color($amount > 0 ? 'danger' : 'success')
                ->url($worker instanceof Worker
                    ? CleaningFinancialPenaltyResource::getUrl('index', ['tableFilters' => ['worker_id' => ['value' => $worker->id]]])
                    : null),
        ];
    }
}
