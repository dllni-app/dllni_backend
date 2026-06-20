<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningTimeWarningService
{
    public function accept(CleaningTimeWarning $warning, ?int $additionalMinutes = null): CleaningTimeWarning
    {
        return DB::transaction(function () use ($warning, $additionalMinutes): CleaningTimeWarning {
            $warning = CleaningTimeWarning::query()
                ->lockForUpdate()
                ->findOrFail($warning->id);

            if ($warning->worker_responded_at !== null) {
                throw new InvalidArgumentException('Extension request has already been responded to.');
            }

            $booking = $warning->booking;
            if (! $booking instanceof CleaningBooking) {
                throw new InvalidArgumentException('Extension request booking is invalid.');
            }

            $booking = CleaningBooking::query()
                ->lockForUpdate()
                ->findOrFail($booking->id);

            $quotedAmount = round((float) ($warning->quoted_amount ?? 0), 2);

            if ($warning->price_applied_at === null && $quotedAmount > 0) {
                $booking->forceFill([
                    'extension_fee_total' => round((float) ($booking->extension_fee_total ?? 0) + $quotedAmount, 2),
                    'total_price' => round((float) ($booking->total_price ?? 0) + $quotedAmount, 2),
                ])->save();
            }

            $warning->update([
                'worker_response' => CleaningTimeWarningResponse::ExtendTime,
                'worker_responded_at' => now(),
                'additional_minutes' => $warning->additional_minutes ?? $additionalMinutes,
                'price_applied_at' => $warning->price_applied_at ?? now(),
            ]);

            return $warning->fresh();
        });
    }

    public function reject(CleaningTimeWarning $warning, ?string $message = null): CleaningTimeWarning
    {
        return DB::transaction(static function () use ($warning, $message): CleaningTimeWarning {
            $warning = CleaningTimeWarning::query()
                ->lockForUpdate()
                ->findOrFail($warning->id);

            if ($warning->worker_responded_at !== null) {
                throw new InvalidArgumentException('Extension request has already been responded to.');
            }

            $booking = $warning->booking;
            if (! $booking instanceof CleaningBooking) {
                throw new InvalidArgumentException('Extension request booking is invalid.');
            }

            $booking = CleaningBooking::query()
                ->lockForUpdate()
                ->findOrFail($booking->id);

            $warning->update([
                'worker_response' => CleaningTimeWarningResponse::CommitCurrentTime,
                'worker_responded_at' => now(),
                'worker_reject_message' => $message,
            ]);

            $booking->update([
                'status' => CleaningBookingStatus::Completed,
                'work_finished_at' => $booking->work_finished_at ?? now(),
                'customer_confirmed_at' => $booking->customer_confirmed_at ?? now(),
            ]);

            return $warning->fresh();
        });
    }
}
