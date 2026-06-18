<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use App\Models\WorkerTrustLog;
use Illuminate\Support\Facades\DB;

final class WorkerTrustService
{
    public function applyBookingCancellationPenalty(Worker $worker): void
    {
        $penalty = max(0, (int) config('cleaning.trust.booking_cancel_penalty', 10));

        if ($penalty === 0) {
            return;
        }

        DB::transaction(function () use ($worker, $penalty): void {
            $lockedWorker = Worker::query()
                ->whereKey($worker->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedWorker instanceof Worker) {
                return;
            }

            $scoreAfter = max(0, (int) $lockedWorker->trust_score - $penalty);

            if ($scoreAfter === (int) $lockedWorker->trust_score) {
                return;
            }

            WorkerTrustLog::query()->create([
                'worker_id' => $lockedWorker->id,
                'reason' => 'booking_cancelled_by_worker',
                'score_delta' => -$penalty,
            ]);

            $lockedWorker->forceFill([
                'trust_score' => $scoreAfter,
            ])->save();
        });
    }
}
