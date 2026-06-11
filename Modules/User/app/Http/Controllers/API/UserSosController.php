<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Enums\SystemAlertStatus;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Order;
use Modules\User\Http\Requests\UserSosStoreRequest;
use Modules\User\Http\Resources\UserSosResource;

final class UserSosController
{
    public function __invoke(UserSosStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userId = (int) $request->user()->id;

        $order = Order::query()
            ->whereKey((int) $data['order_id'])
            ->where('user_id', $userId)
            ->first();

        abort_if($order === null, 403, 'You cannot create an SOS request for this order.');

        $sos = DB::transaction(function () use ($data, $order, $userId): SosAlert {
            $sos = SosAlert::query()->create([
                'user_id' => $userId,
                'order_id' => $order->id,
                'booking_id' => $order->id,
                'booking_type' => Order::class,
                'emergency_type' => EmergencyType::SafetyThreat->value,
                'message' => $data['message'],
                'source' => 'user',
                'status' => SOSStatus::Pending->value,
                'triggered_at' => now(),
            ]);

            SystemAlert::query()->create([
                'booking_id' => $order->id,
                'booking_type' => Order::class,
                'alert_type' => AlertType::SOSTriggered->value,
                'severity' => AlertSeverity::Critical->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => [
                    'source' => 'user_sos',
                    'sos_alert_id' => $sos->id,
                    'user_id' => $userId,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'message' => $data['message'],
                ],
            ]);

            return $sos;
        });

        return response()->json([
            'success' => true,
            'message' => 'SOS request sent successfully.',
            'data' => UserSosResource::make($sos)->resolve($request),
        ], 201);
    }
}
