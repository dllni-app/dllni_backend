<?php

declare(strict_types=1);

namespace Modules\Supermarket\Observers;

use App\Notifications\NewSmOrderDashboardNotification;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\Notification;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderObserver
{
    public function created(SmOrder $order): void
    {
        $admins = DashboardAdminRecipients::all();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewSmOrderDashboardNotification($order));
        }
    }
}
