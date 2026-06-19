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
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderSosStoreRequest;
use Modules\User\Http\Resources\UserCleaningSosResource;

final class UserCleaningOrderSosController
{
    public function __invoke(UserCleaningOrderSosStoreRequest $request, int $order): JsonResponse
    {
        $data = $request->validated();
        $userId = (int) $request->user()->id;

        $booking = CleaningBooking::query()
            ->whereKey($order)
            ->where('customer_id', $userId)
            ->firstOrFail();

        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value
            : (string) $booking->status;

        if (in_array($status, [CleaningBookingStatus::Completed->value, CleaningBookingStatus::Cancelled->value], true)) {
            throw ValidationException::withMessages([
                'order' => ['Cannot create an SOS request for a completed or cancelled cleaning order.'],
            ]);
        }

        $activeSos = SosAlert::query()
            ->where('user_id', $userId)
            ->where('booking_id', $booking->id)
            ->where('booking_type', CleaningBooking::class)
            ->whereIn('status', [SOSStatus::Triggered->value, SOSStatus::Acknowledged->value])
            ->latest('id')
            ->first();

        if ($activeSos instanceof SosAlert) {
            return response()->json([
                'success' => true,
                'message' => 'Cleaning SOS request already exists.',
                'data' => UserCleaningSosResource::make($activeSos)->resolve($request),
            ]);
        }

        $sos = DB::transaction(function () use ($data, $booking, $userId): SosAlert {
            $latitude = $data['latitude'] ?? null;
            $longitude = $data['longitude'] ?? null;

            $sos = SosAlert::query()->create([
                'user_id' => $userId,
                'order_id' => null,
                'booking_id' => $booking->id,
                'booking_type' => CleaningBooking::class,
                'emergency_type' => $data['emergency_type'],
                'message' => $data['message'],
                'source' => 'booking',
                'status' => SOSStatus::Triggered->value,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'triggered_at' => now(),
            ]);

            SystemAlert::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => CleaningBooking::class,
                'alert_type' => AlertType::SOSTriggered->value,
                'severity' => AlertSeverity::Critical->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => [
                    'source' => 'user_cleaning_order_sos',
                    'sos_alert_id' => $sos->id,
                    'user_id' => $userId,
                    'order_id' => $booking->id,
                    'booking_id' => $booking->id,
                    'order_number' => $booking->booking_number,
                    'message' => $data['message'],
                    'emergency_type' => $data['emergency_type'],
                    'latitude' => $latitude,
                    'longitude' => $longitude,
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
