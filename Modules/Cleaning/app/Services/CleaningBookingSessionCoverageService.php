<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingSessionCoverageService
{
    public function refresh(CleaningBookingSession $session): CleaningBookingSession
    {
        return DB::transaction(function () use ($session): CleaningBookingSession {
            /** @var CleaningBookingSession $locked */
            $locked = CleaningBookingSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $acceptedCount = $locked->workerAssignments()
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->count();

            $requiredCount = max(1, (int) ($locked->required_workers ?? 1));

            $coverage = match (true) {
                $acceptedCount <= 0 => CleaningBookingSessionCoverageStatus::Searching,
                $acceptedCount < $requiredCount => CleaningBookingSessionCoverageStatus::PartiallyCovered,
                default => CleaningBookingSessionCoverageStatus::FullyCovered,
            };

            if ($locked->coverage_status !== $coverage) {
                $locked->forceFill([
                    'coverage_status' => $coverage->value,
                ])->save();
            }

            return $locked->fresh(['workerAssignments']) ?? $locked;
        });
    }

    public function isSeatAvailable(CleaningBookingSession $session): bool
    {
        if ($session->isTerminal()) {
            return false;
        }

        return $session->remainingWorkerCount() > 0;
    }
}
