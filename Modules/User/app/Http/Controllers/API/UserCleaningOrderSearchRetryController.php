<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Resources\UserCleaningBookingResource;

final class UserCleaningOrderSearchRetryController
{
    public function __invoke(Request $request, int $order): JsonResponse
    {
        $booking = DB::transaction(static function () use ($request, $order): CleaningBooking {
            $booking = CleaningBooking::query()
                ->where('customer_id', (int) $request->user()->id)
                ->lockForUpdate()
                ->findOrFail($order);

            $status = $booking->status instanceof CleaningBookingStatus
                ? $booking->status->value : (string) $booking->status;

            // An accepted worker changes the cancellation rules and may not
            // enter the retry flow, even if another team seat is still open.
            if ($status !== CleaningBookingStatus::Pending->value ||
                ! $booking->searchWindowExpired()) {
                abort(409, 'هذا الطلب لم تنته مهلة البحث فيه، أو قبله عامل. راجع تفاصيله.');
            }

            $serviceDate = $booking->scheduled_date?->format('Y-m-d');
            $serviceTime = (string) ($booking->scheduled_time ?? '');
            if ($serviceDate === null ||
                ($serviceDate.' '.($serviceTime !== '' ? $serviceTime : '00:00')) <= now()->format('Y-m-d H:i')) {
                abort(422, 'موعد الخدمة انتهى. ألغِ الطلب وأنشئ طلباً جديداً بموعد مستقبلي.');
            }

            $booking->forceFill([
                'search_started_at' => now(),
                'search_retry_count' => (int) ($booking->search_retry_count ?? 0) + 1,
            ])->save();

            return $booking->fresh();
        });

        // The normal dispatch flow handles eligibility, radius, and notifications.
        NotifyEligibleWorkersNewOrderJob::dispatch((int) $booking->id);

        return response()->json([
            'message' => 'تم تجديد نافذة تنبيهات البحث لست ساعات، ويبقى البحث نشطاً ما دام موعد الخدمة قادماً.',
            'data' => UserCleaningBookingResource::make($booking),
        ]);
    }
}
