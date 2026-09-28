<?php

declare(strict_types=1);

namespace Modules\Resturants\Observers;

use App\Notifications\NewRestaurantOrderDashboardNotification;
use App\Services\Coupons\PlatformCouponRedemptionService;
use App\Services\MerchantOrderFinancialSnapshotService;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\Notification;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;

final class OrderObserver
{
    public function __construct(
        private readonly MerchantOrderFinancialSnapshotService $financials,
        private readonly PlatformCouponRedemptionService $platformCoupons,
    ) {}

    public function created(Order $order): void
    {
        $this->financials->initializeRestaurant($order);

        $admins = DashboardAdminRecipients::all();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewRestaurantOrderDashboardNotification($order->fresh()));
        }
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status') || $order->status !== OrderStatus::Cancelled) {
            return;
        }

        $this->platformCoupons->reverseForOrder(
            $order,
            (string) ($order->cancellation_reason ?? 'Restaurant order cancelled'),
            auth()->id(),
        );
    }
}
