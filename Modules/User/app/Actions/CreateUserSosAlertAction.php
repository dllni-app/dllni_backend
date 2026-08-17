<?php

declare(strict_types=1);

namespace Modules\User\Actions;

use App\Enums\EmergencyType;
use App\Models\SosAlert;
use App\Models\User;
use App\Notifications\NewUserSosDashboardNotification;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Resturants\Models\Order;

final class CreateUserSosAlertAction
{
    public function execute(User $user, Order $order, string $message): SosAlert
    {
        $sos = DB::transaction(function () use ($user, $order, $message): SosAlert {
            return SosAlert::query()->create([
                'user_id' => $user->getKey(),
                'order_id' => $order->getKey(),
                'booking_id' => $order->getKey(),
                'booking_type' => Order::class,
                'emergency_type' => EmergencyType::SafetyThreat->value,
                'message' => mb_trim($message),
                'source' => 'user',
                'status' => 'pending',
                'triggered_at' => now(),
            ]);
        });

        $admins = DashboardAdminRecipients::all();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewUserSosDashboardNotification($sos));
        }

        return $sos;
    }
}
