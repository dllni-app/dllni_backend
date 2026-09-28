<?php

declare(strict_types=1);

namespace App\Filament\Company\Widgets;

use App\Filament\Company\Resources\DeliveryDrivers\DeliveryDriverResource;
use App\Filament\Company\Resources\DeliveryOrders\DeliveryOrderResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class DeliveryKpiStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser(auth()->user());
        $baseQuery = DeliveryOrder::query()->where('company_id', $companyId);

        $activeStatuses = [
            DeliveryOrderStatus::WaitingMerchantReady->value,
            DeliveryOrderStatus::SearchingForDriver->value,
            DeliveryOrderStatus::Dispatching->value,
            DeliveryOrderStatus::Offered->value,
            DeliveryOrderStatus::Accepted->value,
            DeliveryOrderStatus::InProgress->value,
            DeliveryOrderStatus::PickedUp->value,
            DeliveryOrderStatus::ReturningToMerchant->value,
        ];
        $dispatchAttentionStatuses = [
            DeliveryOrderStatus::SearchingForDriver->value,
            DeliveryOrderStatus::Dispatching->value,
            DeliveryOrderStatus::Offered->value,
            DeliveryOrderStatus::Stopped->value,
        ];
        $staleCutoff = now()->subMinutes((int) config('delivery.dispatch.stale_location_minutes', 5));
        $staleDrivers = DeliveryDriver::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_suspended', false)
            ->where(function ($query) use ($staleCutoff): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $staleCutoff);
            })
            ->count();

        return [
            Stat::make(__('delivery_company.orders.stats.total'), (clone $baseQuery)->count())
                ->icon('heroicon-o-shopping-bag')
                ->color('primary')
                ->url(DeliveryOrderResource::getUrl('index', panel: 'company')),
            Stat::make(__('delivery_company.orders.stats.active'), (clone $baseQuery)->whereIn('status', $activeStatuses)->count())
                ->icon('heroicon-o-truck')
                ->color('info')
                ->url(DeliveryOrderResource::getUrl('index', panel: 'company')),
            Stat::make(__('delivery_company.orders.stats.dispatch_attention'), (clone $baseQuery)->whereIn('status', $dispatchAttentionStatuses)->count())
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->url(DeliveryOrderResource::getUrl('index', panel: 'company')),
            Stat::make(__('delivery_company.orders.stats.stale_drivers'), $staleDrivers)
                ->icon('heroicon-o-map-pin')
                ->color($staleDrivers > 0 ? 'danger' : 'success')
                ->url(DeliveryDriverResource::getUrl('index', panel: 'company')),
            Stat::make(
                __('delivery_company.orders.stats.returning'),
                (clone $baseQuery)->where('status', DeliveryOrderStatus::ReturningToMerchant->value)->count(),
            )
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->url(DeliveryOrderResource::getUrl('index', panel: 'company')),
            Stat::make(__('delivery_company.orders.stats.stopped'), (clone $baseQuery)->where('status', DeliveryOrderStatus::Stopped->value)->count())
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->url(DeliveryOrderResource::getUrl('index', panel: 'company')),
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
