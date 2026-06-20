<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningTimeWarningService
{
    public function accept(CleaningTimeWarning $warning, ?int $additionalMinutes = null): CleaningTimeWarning
    {
        $warning = DB::transaction(function () use ($warning, $additionalMinutes): CleaningTimeWarning {
            $warning = CleaningTimeWarning::query()->lockForUpdate()->findOrFail($warning->id);

            if ($warning->worker_responded_at !== null) {
                throw new InvalidArgumentException('Extension request has already been responded to.');
            }

            $booking = $this->lockedBooking($warning);
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

            $booking->update([
                'status' => CleaningBookingStatus::InProgress,
                'work_finished_at' => null,
            ]);

            return $warning->fresh(['booking']);
        });

        $this->broadcastDecision($warning, 'extension_accepted');

        return $warning;
    }

    public function reject(CleaningTimeWarning $warning, ?string $message = null): CleaningTimeWarning
    {
        $warning = DB::transaction(function () use ($warning, $message): CleaningTimeWarning {
            $warning = CleaningTimeWarning::query()->lockForUpdate()->findOrFail($warning->id);

            if ($warning->worker_responded_at !== null) {
                throw new InvalidArgumentException('Extension request has already been responded to.');
            }

            $booking = $this->lockedBooking($warning);

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

            return $warning->fresh(['booking']);
        });

        $this->broadcastDecision($warning, 'extension_rejected', $message);

        return $warning;
    }

    private function lockedBooking(CleaningTimeWarning $warning): CleaningBooking
    {
        $booking = $warning->booking;

        if (! $booking instanceof CleaningBooking) {
            throw new InvalidArgumentException('Extension request booking is invalid.');
        }

        return CleaningBooking::query()->lockForUpdate()->findOrFail($booking->id);
    }

    private function broadcastDecision(CleaningTimeWarning $warning, string $decision, ?string $message = null): void
    {
        $booking = $warning->relationLoaded('booking') ? $warning->booking : $warning->booking()->first();

        if (! $booking instanceof CleaningBooking) {
            return;
        }

        $status = $booking->status?->value ?? (string) $booking->status;
        $occurredAt = now()->toIso8601String();

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'bookingId' => $booking->id,
            'status' => $status,
            'workerId' => $booking->worker_id,
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'warningId' => $warning->id,
            'decision' => $decision,
            'updatedAt' => $occurredAt,
        ]));

        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $booking->id,
            $booking->worker_id,
            $decision,
            $message,
            $occurredAt,
            $status,
            $warning->id,
        ));
    }
}
