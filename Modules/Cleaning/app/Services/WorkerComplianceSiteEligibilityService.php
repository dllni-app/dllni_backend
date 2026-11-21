<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Throwable;

final class WorkerComplianceSiteEligibilityService
{
    public function captureBookingAssignment(CleaningBookingWorkerAssignment $assignment): void
    {
        $status = (string) ($assignment->status?->value ?? $assignment->status);
        if ($assignment->accepted_at === null || ! in_array($status, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
            return;
        }

        $booking = $assignment->booking()->first();
        if (! $booking instanceof CleaningBooking) {
            return;
        }

        $this->capture(
            workerId: (int) $assignment->worker_id,
            booking: $booking,
            sourceType: 'booking_assignment',
            sourceAssignmentId: (int) $assignment->id,
            verifiedAt: $assignment->accepted_at,
        );

        if ($status === CleaningBookingWorkerAssignmentStatus::Completed->value) {
            $this->markBookingAssignmentEligible($assignment, $booking);
        } elseif (in_array($status, [
            CleaningBookingWorkerAssignmentStatus::Rejected->value,
            CleaningBookingWorkerAssignmentStatus::Withdrawn->value,
            CleaningBookingWorkerAssignmentStatus::Cancelled->value,
        ], true)) {
            $this->deleteSource('booking_assignment', (int) $assignment->id);
        }
    }

    public function captureSessionAssignment(CleaningBookingSessionWorkerAssignment $assignment): void
    {
        $status = (string) ($assignment->status?->value ?? $assignment->status);
        if ($assignment->accepted_at === null || ! in_array($status, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
            return;
        }

        $session = $assignment->session()->first();
        $booking = $session?->booking()->first();
        if (! $session || ! $booking instanceof CleaningBooking) {
            return;
        }

        $this->capture(
            workerId: (int) $assignment->worker_id,
            booking: $booking,
            sourceType: 'session_assignment',
            sourceAssignmentId: (int) $assignment->id,
            verifiedAt: $assignment->accepted_at,
            sessionId: (int) $session->id,
        );

        if ($status === CleaningBookingWorkerAssignmentStatus::Completed->value) {
            $sessionStatus = (string) ($session->status?->value ?? $session->status);
            if ($sessionStatus === CleaningBookingSessionStatus::Completed->value
                && $assignment->started_travel_at !== null
                && $assignment->arrived_at !== null
                && $assignment->start_approved_at !== null
                && $assignment->work_finished_at !== null) {
                $this->markEligible(
                    'session_assignment',
                    (int) $assignment->id,
                    $assignment->work_finished_at,
                );
            }
        } elseif (in_array($status, [
            CleaningBookingWorkerAssignmentStatus::Rejected->value,
            CleaningBookingWorkerAssignmentStatus::Withdrawn->value,
            CleaningBookingWorkerAssignmentStatus::Cancelled->value,
        ], true) && $assignment->work_finished_at === null) {
            $this->deleteSource('session_assignment', (int) $assignment->id);
        }
    }

    public function captureLegacyBooking(CleaningBooking $booking): void
    {
        if ((int) $booking->number_of_workers !== 1
            || $booking->worker_id === null
            || $booking->sessions()->exists()
            || $booking->workerAssignments()->exists()) {
            return;
        }

        $status = (string) ($booking->status?->value ?? $booking->status);
        if ($status === CleaningBookingStatus::Cancelled->value && $booking->work_finished_at === null) {
            $this->deleteSource('legacy_booking', (int) $booking->id);

            return;
        }

        if (! in_array($status, [
            CleaningBookingStatus::WorkerAssigned->value,
            CleaningBookingStatus::AwaitingStartVerification->value,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
            CleaningBookingStatus::InProgress->value,
            CleaningBookingStatus::AwaitingCustomerCompletion->value,
            CleaningBookingStatus::TimeExtensionRequested->value,
            CleaningBookingStatus::Completed->value,
        ], true)) {
            return;
        }

        $this->capture(
            workerId: (int) $booking->worker_id,
            booking: $booking,
            sourceType: 'legacy_booking',
            sourceAssignmentId: (int) $booking->id,
            verifiedAt: $booking->started_travel_at ?? $booking->updated_at,
        );

        if ($status === CleaningBookingStatus::Completed->value
            && $booking->started_travel_at !== null
            && $booking->arrived_at !== null
            && $booking->work_finished_at !== null
            && $booking->customer_confirmed_at !== null) {
            $this->markEligible('legacy_booking', (int) $booking->id, $booking->work_finished_at);
        }
    }

    private function capture(
        int $workerId,
        CleaningBooking $booking,
        string $sourceType,
        int $sourceAssignmentId,
        mixed $verifiedAt,
        ?int $sessionId = null,
    ): void {
        if ($workerId <= 0 || $booking->address_latitude === null || $booking->address_longitude === null) {
            return;
        }

        $latitude = (float) $booking->address_latitude;
        $longitude = (float) $booking->address_longitude;
        if (! is_finite($latitude) || ! is_finite($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return;
        }

        $coordinateFingerprint = sprintf('%.5F:%.5F', $latitude, $longitude);
        $siteKeySecret = (string) config('cleaning.worker_compliance.site_key_secret', config('app.key'));
        if ($siteKeySecret === '') {
            return;
        }

        $unservedRetentionDays = max(1, (int) config('cleaning.worker_compliance.unserved_site_snapshot_retention_days', 30));
        $snapshotExpiresAt = CarbonImmutable::parse($verifiedAt)->addDays($unservedRetentionDays);
        try {
            $scheduledStart = CarbonImmutable::parse(
                $booking->scheduled_date->format('Y-m-d').' '.$booking->scheduled_time,
                (string) config('cleaning_booking_v2.timezone'),
            )->addDays($unservedRetentionDays);
            if ($scheduledStart->greaterThan($snapshotExpiresAt)) {
                $snapshotExpiresAt = $scheduledStart;
            }
        } catch (Throwable) {
            // The acceptance-time expiry remains the conservative fallback.
        }

        DB::table('worker_compliance_site_eligibilities')->insertOrIgnore([
            'worker_id' => $workerId,
            'cleaning_booking_id' => (int) $booking->id,
            'cleaning_booking_session_id' => $sessionId,
            'source_type' => $sourceType,
            'source_assignment_id' => $sourceAssignmentId,
            'site_key' => hash_hmac('sha256', $coordinateFingerprint, $siteKeySecret),
            'latitude_encrypted' => Crypt::encryptString((string) $latitude),
            'longitude_encrypted' => Crypt::encryptString((string) $longitude),
            'site_verified_at' => $verifiedAt,
            'served_at' => null,
            'eligible_until' => $snapshotExpiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function markBookingAssignmentEligible(CleaningBookingWorkerAssignment $assignment, CleaningBooking $booking): void
    {
        if ($assignment->started_travel_at === null
            || $assignment->arrived_at === null
            || $assignment->work_finished_at === null) {
            return;
        }

        if ((int) $booking->number_of_workers > 1) {
            $attendanceConfirmed = DB::table('cleaning_worker_attendance_proofs')
                ->where('worker_assignment_id', $assignment->id)
                ->whereNotNull('confirmed_at')
                ->exists();
            if (! $attendanceConfirmed) {
                return;
            }
        }

        $this->markEligible('booking_assignment', (int) $assignment->id, $assignment->work_finished_at);
    }

    private function markEligible(string $sourceType, int $sourceAssignmentId, mixed $servedAt): void
    {
        $retentionDays = max(1, (int) config('cleaning.worker_compliance.site_eligibility_retention_days', 90));
        DB::table('worker_compliance_site_eligibilities')
            ->where('source_type', $sourceType)
            ->where('source_assignment_id', $sourceAssignmentId)
            ->update([
                'served_at' => $servedAt,
                'eligible_until' => \Illuminate\Support\Carbon::parse($servedAt)->addDays($retentionDays),
                'updated_at' => now(),
            ]);
    }

    private function deleteSource(string $sourceType, int $sourceAssignmentId): void
    {
        DB::table('worker_compliance_site_eligibilities')
            ->where('source_type', $sourceType)
            ->where('source_assignment_id', $sourceAssignmentId)
            ->whereNull('served_at')
            ->delete();
    }
}
