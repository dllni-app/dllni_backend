<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Notifications\UserAccountDashboardNotification;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class DashboardUserAccountNotificationService
{
    public function registered(User $user): void
    {
        $this->send($user, 'registered');
    }

    public function nameChanged(User $user, string $previousName): void
    {
        $this->send($user, 'name_changed', $previousName);
    }

    private function send(User $user, string $event, ?string $previousName = null): void
    {
        try {
            $admins = DashboardAdminRecipients::all();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send(
                $admins,
                new UserAccountDashboardNotification($user, $event, $previousName),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
