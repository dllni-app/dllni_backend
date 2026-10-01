<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DisputeStatus;
use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\DeliveryCompanies\DeliveryCompanyResource;
use App\Filament\Resources\DeliveryDisputes\DeliveryDisputeResource;
use App\Filament\Resources\DeliveryDrivers\DeliveryDriverResource;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Support\AdminDeliveryLabels;
use App\Models\Dispute;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;

final class DeliveryOperationsHub extends Page
{
    use AuthorizesPlatformAdminResource;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'مركز عمليات التوصيل';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.delivery-operations-hub';

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::delivery();
    }

    public static function canAccess(): bool
    {
        return self::dashboardAllowed('platform_delivery_operations.view');
    }

    public function getTitle(): string
    {
        return 'مركز عمليات التوصيل';
    }

    public function getSubheading(): ?string
    {
        return 'متابعة الحالات التي تحتاج تدخل الإدارة عبر جميع شركات ومندوبي التوصيل.';
    }

    public function getViewData(): array
    {
        $activeStatuses = [
            DeliveryOrderStatus::Dispatching->value,
            DeliveryOrderStatus::Offered->value,
            DeliveryOrderStatus::Accepted->value,
            DeliveryOrderStatus::InProgress->value,
            DeliveryOrderStatus::PickedUp->value,
        ];

        $activeCount = DeliveryOrder::query()->whereIn('status', $activeStatuses)->count();
        $stoppedCount = DeliveryOrder::query()->where('status', DeliveryOrderStatus::Stopped->value)->count();
        $noDriverCount = DeliveryOrder::query()
            ->whereIn('status', [
                DeliveryOrderStatus::Dispatching->value,
                DeliveryOrderStatus::Offered->value,
            ])
            ->whereNull('driver_id')
            ->count();
        $suspendedDriversCount = DeliveryDriver::query()->where('is_suspended', true)->count();
        $staleDriversCount = DeliveryDriver::query()
            ->where('is_active', true)
            ->whereIn('availability_status', ['online', 'busy'])
            ->where(function ($query): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', now()->subMinutes(15));
            })
            ->count();
        $openDisputesCount = Dispute::query()
            ->where('booking_type', 'delivery_order')
            ->whereIn('status', [
                DisputeStatus::Open->value,
                DisputeStatus::UnderReview->value,
            ])
            ->count();

        $stoppedOrders = DeliveryOrder::query()
            ->where('status', DeliveryOrderStatus::Stopped->value)
            ->with(['company', 'driver'])
            ->latest('stopped_at')
            ->limit(6)
            ->get()
            ->map(fn (DeliveryOrder $order): array => [
                'label' => $order->order_number.' — '.($order->company?->name ?? 'بدون شركة'),
                'meta' => $order->stop_reason ?: 'توقف الإسناد',
                'url' => DeliveryOrderResource::getUrl('view', ['record' => $order]),
                'tone' => 'danger',
            ])
            ->all();

        $noDriverOrders = DeliveryOrder::query()
            ->whereIn('status', [
                DeliveryOrderStatus::Dispatching->value,
                DeliveryOrderStatus::Offered->value,
            ])
            ->whereNull('driver_id')
            ->with('company')
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->map(fn (DeliveryOrder $order): array => [
                'label' => $order->order_number.' — '.($order->company?->name ?? 'بدون شركة'),
                'meta' => 'مرحلة الإسناد: '.AdminDeliveryLabels::dispatchPhase($order->dispatch_phase),
                'url' => DeliveryOrderResource::getUrl('view', ['record' => $order]),
                'tone' => 'warning',
            ])
            ->all();

        $openDisputes = Dispute::query()
            ->where('booking_type', 'delivery_order')
            ->whereIn('status', [
                DisputeStatus::Open->value,
                DisputeStatus::UnderReview->value,
            ])
            ->with(['booking.company', 'booking.driver'])
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->map(fn (Dispute $dispute): array => [
                'label' => $dispute->ticket_number.' — '.($dispute->booking?->order_number ?? '—'),
                'meta' => ($dispute->booking?->company?->name ?? '—').' — '.($dispute->booking?->driver?->first_name ?? 'بدون مندوب'),
                'url' => DeliveryDisputeResource::getUrl('view', ['record' => $dispute]),
                'tone' => 'danger',
            ])
            ->all();

        $staleDrivers = DeliveryDriver::query()
            ->where('is_active', true)
            ->whereIn('availability_status', ['online', 'busy'])
            ->where(function ($query): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', now()->subMinutes(15));
            })
            ->with('company')
            ->orderBy('last_seen_at')
            ->limit(6)
            ->get()
            ->map(fn (DeliveryDriver $driver): array => [
                'label' => ($driver->first_name ?: '#'.$driver->id).' — '.($driver->company?->name ?? '—'),
                'meta' => $driver->last_seen_at?->diffForHumans() ?? 'لا يوجد آخر ظهور',
                'url' => DeliveryDriverResource::getUrl('view', ['record' => $driver]),
                'tone' => 'warning',
            ])
            ->all();

        return [
            'overviewKpis' => [
                ['label' => 'طلبات نشطة', 'value' => $activeCount, 'tone' => 'primary'],
                ['label' => 'طلبات متوقفة', 'value' => $stoppedCount, 'tone' => 'danger'],
                ['label' => 'بانتظار مندوب', 'value' => $noDriverCount, 'tone' => 'warning'],
                ['label' => 'مندوبيـن موقوفين', 'value' => $suspendedDriversCount, 'tone' => 'danger'],
                ['label' => 'موقع/ظهور قديم', 'value' => $staleDriversCount, 'tone' => 'warning'],
                ['label' => 'نزاعات توصيل مفتوحة', 'value' => $openDisputesCount, 'tone' => 'danger'],
            ],
            'workflowLinks' => [
                ['label' => 'طلبات التوصيل', 'url' => DeliveryOrderResource::getUrl('index'), 'tone' => 'primary'],
                ['label' => 'مندوبي التوصيل', 'url' => DeliveryDriverResource::getUrl('index'), 'tone' => 'info'],
                ['label' => 'شركات التوصيل', 'url' => DeliveryCompanyResource::getUrl('index'), 'tone' => 'neutral'],
                ['label' => 'نزاعات التوصيل', 'url' => DeliveryDisputeResource::getUrl('index'), 'tone' => 'danger'],
            ],
            'attentionQueues' => [
                [
                    'title' => 'طلبات متوقفة',
                    'count' => $stoppedCount,
                    'items' => $stoppedOrders,
                    'emptyMessage' => 'لا توجد طلبات متوقفة حالياً.',
                ],
                [
                    'title' => 'إسناد بدون مندوب',
                    'count' => $noDriverCount,
                    'items' => $noDriverOrders,
                    'emptyMessage' => 'لا توجد طلبات تنتظر تعيين مندوب.',
                ],
                [
                    'title' => 'مندوبيـن بآخر ظهور قديم',
                    'count' => $staleDriversCount,
                    'items' => $staleDrivers,
                    'emptyMessage' => 'لا توجد حالات موقع أو ظهور قديم.',
                ],
                [
                    'title' => 'نزاعات توصيل مفتوحة',
                    'count' => $openDisputesCount,
                    'items' => $openDisputes,
                    'emptyMessage' => 'لا توجد نزاعات توصيل مفتوحة.',
                ],
            ],
        ];
    }
}
