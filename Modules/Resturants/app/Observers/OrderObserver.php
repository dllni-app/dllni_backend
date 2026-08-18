<?php

declare(strict_types=1);

namespace Modules\Resturants\Observers;

use App\Notifications\NewRestaurantOrderDashboardNotification;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\Notification;
use Modules\Resturants\Models\Order;

final class OrderObserver
{
    public function created(Order $order): void
    {
        $admins = DashboardAdminRecipients::all();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewRestaurantOrderDashboardNotification($order));
        }
    }
}
