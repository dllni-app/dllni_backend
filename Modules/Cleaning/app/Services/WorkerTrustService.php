<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use App\Models\WorkerTrustLog;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

final class WorkerTrustService
{
    public function applyBookingCancellationPenalty(Worker $worker, ?CleaningBooking $booking = null): void
    {
        $penalty = max(0, (int) config('cleaning.trust.booking_cancel_penalty', 10));

        $this->applyPenalty(
            worker: $worker,
            reason: 'booking_cancelled_by_worker',
            penalty: $penalty,
            booking: $booking,
        );
    }

    public function applyRejectAfterAcceptPenalty(Worker $worker, CleaningBooking $booking): void
    {
        $penalty = max(
            0,
            (int) CleaningRuntimeSettings::deposit()->trust_reject_after_accept_penalty,
        );

        $this->applyPenalty(
            worker: $worker,
            reason: 'booking_rejected_after_accept',
            penalty: $penalty,
            booking: $booking,
        );
    }

    private function applyPenalty(
        Worker $worker,
        string $reason,
        int $penalty,
        ?CleaningBooking $booking = null,
    ): void {
        if ($penalty === 0) {
            return;
        }

        DB::transaction(function () use ($worker, $reason, $penalty, $booking): void {
            $lockedWorker = Worker::query()
                ->whereKey($worker->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedWorker instanceof Worker) {
                return;
            }

            $scoreBefore = (int) $lockedWorker->trust_score;
            $scoreAfter = max(0, $scoreBefore - $penalty);

            if ($scoreAfter === $scoreBefore) {
                return;
            }

            WorkerTrustLog::query()->create([
                'worker_id' => $lockedWorker->id,
                'cleaning_booking_id' => $booking?->id,
                'reason' => $reason,
                'score_delta' => -$penalty,
                'score_before' => $scoreBefore,
                'score_after' => $scoreAfter,
            ]);

            $lockedWorker->forceFill([
                'trust_score' => $scoreAfter,
            ])->save();
        });
    }
}
