<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\SupportCase;
use App\Notifications\NewSupportCaseDashboardNotification;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\Notification;

final class SupportCaseObserver
{
    public function created(SupportCase $supportCase): void
    {
        $admins = DashboardAdminRecipients::all();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new NewSupportCaseDashboardNotification($supportCase));
    }
}
