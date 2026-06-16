<?php

declare(strict_types=1);

namespace App\Filament\Company\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class DeliveryKpiStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser(auth()->user());
        $baseQuery = DeliveryOrder::query()->where('company_id', $companyId);

        $activeStatuses = [
            DeliveryOrderStatus::Dispatching->value,
            DeliveryOrderStatus::Offered->value,
            DeliveryOrderStatus::Accepted->value,
            DeliveryOrderStatus::InProgress->value,
            DeliveryOrderStatus::PickedUp->value,
        ];

        return [
            Stat::make(__('delivery_company.orders.stats.total'), (clone $baseQuery)->count())
                ->icon('heroicon-o-shopping-bag')
                ->color('primary'),
            Stat::make(__('delivery_company.orders.stats.active'), (clone $baseQuery)->whereIn('status', $activeStatuses)->count())
                ->icon('heroicon-o-truck')
                ->color('info'),
            Stat::make(__('delivery_company.orders.stats.stopped'), (clone $baseQuery)->where('status', DeliveryOrderStatus::Stopped->value)->count())
                ->icon('heroicon-o-pause-circle')
                ->color('warning'),
            Stat::make(
                __('delivery_company.orders.stats.completed_today'),
                (clone $baseQuery)
                    ->where('status', DeliveryOrderStatus::Completed->value)
                    ->whereDate('completed_at', today())
                    ->count(),
            )
                ->icon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }
}
