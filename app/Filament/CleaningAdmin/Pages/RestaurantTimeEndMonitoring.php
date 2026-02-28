<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use UnitEnum;

final class RestaurantTimeEndMonitoring extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $title = 'مراقبة نهاية الوقت';

    protected static ?string $navigationLabel = 'مراقبة نهاية الوقت';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected string $view = 'filament.cleaning-admin.pages.restaurant-time-end-monitoring';

    public static function getNavigationTooltip(): ?string
    {
        return 'مراقبة الطلبات التي تقترب من نهاية الوقت أو تتأخر عن الإنهاء.';
    }

    public function getViewData(): array
    {
        $now = now();

        $orders = Order::query()
            ->whereIn('status', [OrderStatus::Accepted, OrderStatus::Preparing])
            ->whereNotNull('accepted_at')
            ->whereNotNull('estimated_preparation_minutes')
            ->with(['restaurant:id,name', 'user:id,name,phone'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(function (Order $order) use ($now): array {
                $expectedEnd = $order->accepted_at?->copy()->addMinutes((int) $order->estimated_preparation_minutes);
                $minutesRemaining = $expectedEnd ? (int) $now->diffInMinutes($expectedEnd, false) : 0;

                $statusLabel = 'On Track';
                if ($minutesRemaining <= 15 && $minutesRemaining >= 0) {
                    $statusLabel = 'Warning';
                } elseif ($minutesRemaining < 0) {
                    $statusLabel = 'Overdue';
                }

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'restaurant' => $order->restaurant?->name,
                    'customer' => $order->user?->name,
                    'customer_phone' => $order->user?->phone,
                    'status' => __('restaurant_admin.enums.order_status.'.$order->status->value),
                    'expected_end_at' => $expectedEnd?->toDateTimeString(),
                    'minutes_remaining' => $minutesRemaining,
                    'monitoring_state' => __('restaurant_admin.enums.monitoring_state.'.$statusLabel),
                ];
            });

        return ['rows' => $orders];
    }
}
