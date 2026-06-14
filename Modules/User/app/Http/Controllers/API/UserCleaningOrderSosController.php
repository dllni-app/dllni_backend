<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SOSStatus;
use App\Enums\SystemAlertStatus;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Resturants\Models\Order;
use Modules\User\Http\Requests\UserCleaningOrderSosStoreRequest;
use Modules\User\Http\Resources\UserCleaningSosResource;

final class UserCleaningOrderSosController
{
    public function __invoke(UserCleaningOrderSosStoreRequest $request, CleaningBooking $order): JsonResponse
    {
        $data = $request->validated();
        $userId = (int) $request->user()->id;

        abort_if((int) $order->customer_id !== $userId, 403, 'You cannot create an SOS request for this order.');

        $sos = DB::transaction(function () use ($data, $order, $userId): SosAlert {
            $sos = SosAlert::query()->create([
                'user_id' => $userId,
                'order_id' => $order->id,
                'booking_id' => $order->id,
                'booking_type' => CleaningBooking::class,
                'emergency_type' => $data['emergency_type'],
                'message' => $data['message'],
                'source' => 'user_cleaning_order',
                'status' => SOSStatus::Pending->value,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'triggered_at' => now(),
            ]);

            SystemAlert::query()->create([
                'booking_id' => $order->id,
                'booking_type' => CleaningBooking::class,
                'alert_type' => AlertType::SOSTriggered->value,
                'severity' => AlertSeverity::Critical->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => [
                    'source' => 'user_cleaning_order_sos',
                    'sos_alert_id' => $sos->id,
                    'user_id' => $userId,
                    'order_id' => $order->id,
                    'order_number' => $order->booking_number,
                    'message' => $data['message'],
                    'emergency_type' => $data['emergency_type'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                ],
            ]);

            return $sos;
        });

        return response()->json([
            'success' => true,
            'message' => 'Cleaning SOS request sent successfully.',
            'data' => UserCleaningSosResource::make($sos)->resolve($request),
        ], 201);
    }
}
