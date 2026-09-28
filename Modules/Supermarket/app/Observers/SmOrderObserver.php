<?php

declare(strict_types=1);

namespace Modules\Supermarket\Observers;

use App\Notifications\NewSmOrderDashboardNotification;
use App\Services\Coupons\PlatformCouponRedemptionService;
use App\Services\MerchantOrderFinancialSnapshotService;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\Notification;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderObserver
{
    public function __construct(
        private readonly MerchantOrderFinancialSnapshotService $financials,
        private readonly PlatformCouponRedemptionService $platformCoupons,
    ) {}

    public function created(SmOrder $order): void
    {
        $this->financials->initializeSupermarket($order);

        $admins = DashboardAdminRecipients::all();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewSmOrderDashboardNotification($order->fresh()));
        }
    }

    public function updated(SmOrder $order): void
    {
        if (! $order->wasChanged('status') || $order->status !== SmOrderStatus::Cancelled) {
            return;
        }

        $this->platformCoupons->reverseForOrder(
            $order,
            (string) ($order->cancellation_reason ?? 'Supermarket order cancelled'),
            auth()->id(),
        );
    }
}
