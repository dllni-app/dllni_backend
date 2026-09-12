<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingSessionCoverageService
{
    public function __construct(
        private readonly RecurringCleaningCoverageNotificationService $recurringCoverageNotifications,
    ) {}

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
            $previousCoverage = $locked->coverage_status instanceof CleaningBookingSessionCoverageStatus
                ? $locked->coverage_status
                : CleaningBookingSessionCoverageStatus::tryFrom((string) $locked->coverage_status);

            if ($previousCoverage !== $coverage) {
                $locked->forceFill([
                    'coverage_status' => $coverage->value,
                ])->save();

                $refreshed = $locked->fresh(['booking.customer', 'workerAssignments']) ?? $locked;
                $this->recurringCoverageNotifications->notifyCoverageChanged(
                    session: $refreshed,
                    previousCoverage: $previousCoverage,
                    currentCoverage: $coverage,
                );
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
