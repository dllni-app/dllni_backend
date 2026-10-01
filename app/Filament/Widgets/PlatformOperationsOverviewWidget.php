<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SystemAlertStatus;
use App\Filament\Pages\DeliveryOperationsHub;
use App\Filament\Pages\RestaurantSectionHub;
use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use App\Models\SystemAlert;
use Filament\Widgets\Widget;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantDisputeStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\RestaurantOrderDispute;
use Modules\Supermarket\Enums\SmDisputeStatus;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderDispute;

final class PlatformOperationsOverviewWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.platform-operations-overview';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $sections = [];

        if (OrderResource::canViewAny()) {
            $late = Order::query()
                ->whereIn('status', [OrderStatus::Accepted->value, OrderStatus::Preparing->value])
                ->whereNotNull('estimated_ready_at')->where('estimated_ready_at', '<', now())->count();
            $pending = Order::query()->where('status', OrderStatus::Pending->value)->count();
            $disputes = RestaurantOrderDispute::query()->whereIn('status', [
                RestaurantDisputeStatus::Open->value, RestaurantDisputeStatus::UnderReview->value,
            ])->count();
            $sections[] = [
                'title' => 'المطاعم', 'tone' => 'primary', 'url' => RestaurantSectionHub::getUrl(),
                'metrics' => [
                    ['label' => __('restaurant_admin.hub.kpis.pending_orders'), 'value' => $pending],
                    ['label' => 'تحضير متأخر', 'value' => $late],
                    ['label' => 'نزاعات مفتوحة', 'value' => $disputes],
                ],
                'items' => Order::query()
                    ->whereIn('status', [OrderStatus::Accepted->value, OrderStatus::Preparing->value])
                    ->whereNotNull('estimated_ready_at')->where('estimated_ready_at', '<', now())
                    ->with('restaurant:id,name')->orderBy('estimated_ready_at')->limit(5)->get()
                    ->map(fn (Order $o): array => [
                        'label' => $o->order_number.' — '.($o->restaurant?->name ?? '—'),
                        'meta' => 'الجاهزية المتوقعة: '.($o->estimated_ready_at?->format('Y-m-d H:i') ?? '—'),
                        'url' => OrderResource::getUrl('view', ['record' => $o]),
                        'tone' => 'danger',
                    ])->all(),
            ];
        }

        if (SmOrderResource::canViewAny()) {
            $late = SmOrder::query()
                ->whereIn('status', [SmOrderStatus::Accepted->value, SmOrderStatus::Preparing->value])
                ->whereNotNull('estimated_ready_at')->where('estimated_ready_at', '<', now())->count();
            $ready = SmOrder::query()->where('status', SmOrderStatus::ReadyForPickup->value)->count();
            $disputes = SmOrderDispute::query()->whereIn('status', [
                SmDisputeStatus::Open->value, SmDisputeStatus::UnderReview->value,
            ])->count();
            $sections[] = [
                'title' => 'السوبرماركت', 'tone' => 'success', 'url' => SupermarketSectionHub::getUrl(),
                'metrics' => [
                    ['label' => 'تحضير متأخر', 'value' => $late],
                    ['label' => 'جاهزة للاستلام', 'value' => $ready],
                    ['label' => 'نزاعات مفتوحة', 'value' => $disputes],
                ],
                'items' => SmOrder::query()
                    ->where('status', SmOrderStatus::ReadyForPickup->value)
                    ->with('store:id,name')->orderBy('ready_for_pickup_at')->limit(5)->get()
                    ->map(fn (SmOrder $o): array => [
                        'label' => $o->order_number.' — '.($o->store?->name ?? '—'),
                        'meta' => 'جاهز منذ: '.($o->ready_for_pickup_at?->format('Y-m-d H:i') ?? '—'),
                        'url' => SmOrderResource::getUrl('view', ['record' => $o]),
                        'tone' => 'warning',
                    ])->all(),
            ];
        }

        if (DeliveryOrderResource::canViewAny()) {
            $stopped = DeliveryOrder::query()->where('status', DeliveryOrderStatus::Stopped->value)->count();
            $noDriver = DeliveryOrder::query()
                ->whereIn('status', [DeliveryOrderStatus::Dispatching->value, DeliveryOrderStatus::Offered->value])
                ->whereNull('driver_id')->count();
            $stale = DeliveryDriver::query()->where('is_active', true)
                ->whereIn('availability_status', ['available', 'online', 'busy'])
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', now()->subMinutes(15)))->count();
            $sections[] = [
                'title' => 'التوصيل', 'tone' => 'warning', 'url' => DeliveryOperationsHub::getUrl(),
                'metrics' => [
                    ['label' => 'طلبات متوقفة', 'value' => $stopped],
                    ['label' => 'بدون مندوب', 'value' => $noDriver],
                    ['label' => 'مندوبيـن بظهور قديم', 'value' => $stale],
                ],
                'items' => DeliveryOrder::query()->where('status', DeliveryOrderStatus::Stopped->value)
                    ->with('company:id,name')->latest('stopped_at')->limit(5)->get()
                    ->map(fn (DeliveryOrder $o): array => [
                        'label' => $o->order_number.' — '.($o->company?->name ?? '—'),
                        'meta' => $o->stop_reason ?: 'توقف الإسناد',
                        'url' => DeliveryOrderResource::getUrl('view', ['record' => $o]),
                        'tone' => 'danger',
                    ])->all(),
            ];
        }

        $alerts = null;
        if (SystemAlertResource::canViewAny()) {
            $alerts = [
                'count' => SystemAlert::query()->whereIn('status', [
                    SystemAlertStatus::New->value, SystemAlertStatus::Acknowledged->value,
                ])->count(),
                'url' => SystemAlertResource::getUrl('index'),
            ];
        }

        return ['sections' => $sections, 'alerts' => $alerts];
    }
}
