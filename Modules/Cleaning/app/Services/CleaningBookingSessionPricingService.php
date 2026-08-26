<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningBookingSessionPricingService
{
    public function __construct(
        private readonly CleaningPricingCalculator $calculator,
    ) {}

    public function initializeFromParent(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(function () use ($booking): CleaningBooking {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $sessions = $booking->sessions()->lockForUpdate()->get();

            if ($sessions->isEmpty()) {
                return $booking;
            }

            $activeForService = $sessions->reject(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::Cancelled);
            $totalHours = max(0.01, (float) $activeForService->sum('duration_hours'));
            $baseTotal = max(0.0, (float) $booking->base_price);
            $remainingBase = $baseTotal;
            $pricedCount = $activeForService->count();
            $pricedIndex = 0;

            foreach ($sessions->values() as $session) {
                if ($session->status === CleaningBookingSessionStatus::Cancelled) {
                    continue;
                }

                $base = $pricedIndex === $pricedCount - 1
                    ? round(max(0.0, $remainingBase), 2)
                    : round($baseTotal * ((float) $session->duration_hours / $totalHours), 2);
                $remainingBase = round($remainingBase - $base, 2);
                $pricedIndex++;
                $provisional = $this->calculator->provisional($base, 0.0);

                $session->forceFill([
                    'base_price' => $base,
                    'travel_fee' => 0,
                    'travel_distance_km' => null,
                    'admin_margin_amount' => (float) $provisional['adminMargin'],
                    'total_price' => round($base + (float) $provisional['adminMargin'], 2),
                    'is_pricing_final' => false,
                ])->saveQuietly();
            }

            return $this->aggregateParent($booking->fresh(['sessions']));
        });
    }

    public function syncAssignmentsAndRecalculate(CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(function () use ($booking): CleaningBooking {
            $booking = CleaningBooking::query()
                ->with(['workerAssignments.worker', 'sessions.workerAssignments'])
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $booking->isEventAssistanceBooking() || $booking->sessions->isEmpty()) {
                return $booking;
            }

            $accepted = $booking->workerAssignments
                ->filter(fn (CleaningBookingWorkerAssignment $assignment): bool => in_array(
                    (string) ($assignment->status?->value ?? $assignment->status),
                    CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                    true,
                ))
                ->values();
            $requiredWorkers = max(1, (int) ($booking->number_of_workers ?? 1));
            $teamFulfilled = $accepted->count() >= $requiredWorkers;
            $acceptedWorkerIds = $accepted->pluck('worker_id')->map(fn ($id): int => (int) $id)->all();

            foreach ($booking->sessions as $session) {
                if ($session->status === CleaningBookingSessionStatus::Cancelled) {
                    $session->workerAssignments()
                        ->whereNotIn('status', [CleaningBookingWorkerAssignmentStatus::Completed->value])
                        ->update(['status' => CleaningBookingWorkerAssignmentStatus::Cancelled->value, 'updated_at' => now()]);
                    continue;
                }

                // Historical execution is immutable. Never create a completed
                // assignment for a worker who joined only after this day ended.
                if ($session->status === CleaningBookingSessionStatus::Completed) {
                    continue;
                }

                $base = max(0.0, (float) $session->base_price);
                $remainingShare = $base;

                foreach ($accepted as $index => $parentAssignment) {
                    $worker = $parentAssignment->worker;
                    if (! $worker instanceof Worker) {
                        continue;
                    }

                    $share = $index === $requiredWorkers - 1 && $accepted->count() >= $requiredWorkers
                        ? round(max(0.0, $remainingShare), 2)
                        : round($base / $requiredWorkers, 2);
                    $remainingShare = round($remainingShare - $share, 2);
                    $workerPricing = $this->calculator->finalizedForWorker(
                        $share,
                        0.0,
                        $booking->address_latitude !== null ? (float) $booking->address_latitude : null,
                        $booking->address_longitude !== null ? (float) $booking->address_longitude : null,
                        $worker,
                    );

                    $sessionAssignment = CleaningBookingSessionWorkerAssignment::query()->firstOrNew([
                        'cleaning_booking_session_id' => $session->id,
                        'worker_id' => $worker->id,
                    ]);
                    $sessionAssignment->cleaning_booking_worker_assignment_id = $parentAssignment->id;
                    if (! $sessionAssignment->exists || in_array((string) ($sessionAssignment->status?->value ?? $sessionAssignment->status), [
                        CleaningBookingWorkerAssignmentStatus::Pending->value,
                        CleaningBookingWorkerAssignmentStatus::Accepted->value,
                        CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
                        CleaningBookingWorkerAssignmentStatus::Rejected->value,
                        CleaningBookingWorkerAssignmentStatus::Withdrawn->value,
                        CleaningBookingWorkerAssignmentStatus::Cancelled->value,
                    ], true)) {
                        $sessionAssignment->status = $this->initialAssignmentStatusForSession($session);
                    }
                    $sessionAssignment->service_share_amount = $share;
                    $sessionAssignment->travel_fee = (float) $workerPricing['travelFee'];
                    $sessionAssignment->admin_margin_amount = (float) $workerPricing['adminMargin'];
                    $sessionAssignment->worker_amount = max(0.0, round(
                        $share + (float) $workerPricing['travelFee'] - (float) $workerPricing['adminMargin'],
                        2,
                    ));
                    $sessionAssignment->currency = (string) config('app.currency', 'SYP');
                    $sessionAssignment->saveQuietly();
                }

                if ($acceptedWorkerIds === []) {
                    $session->workerAssignments()
                        ->whereNotIn('status', [CleaningBookingWorkerAssignmentStatus::Completed->value])
                        ->update(['status' => CleaningBookingWorkerAssignmentStatus::Withdrawn->value, 'updated_at' => now()]);
                } else {
                    $session->workerAssignments()
                        ->whereNotIn('worker_id', $acceptedWorkerIds)
                        ->whereNotIn('status', [CleaningBookingWorkerAssignmentStatus::Completed->value])
                        ->update(['status' => CleaningBookingWorkerAssignmentStatus::Withdrawn->value, 'updated_at' => now()]);
                }

                $session->load('workerAssignments');
                if ($teamFulfilled) {
                    $activeAssignments = $session->workerAssignments->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => in_array(
                        (string) ($assignment->status?->value ?? $assignment->status),
                        CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                        true,
                    ));
                    $travel = round((float) $activeAssignments->sum('travel_fee'), 2);
                    $admin = round((float) $activeAssignments->sum('admin_margin_amount'), 2);
                    $session->forceFill([
                        'travel_fee' => $travel,
                        'admin_margin_amount' => $admin,
                        'travel_distance_km' => null,
                        'total_price' => round($base + $travel + $admin + (float) $session->extension_fee_total + (float) $session->cancellation_fee, 2),
                        'is_pricing_final' => true,
                    ])->saveQuietly();
                } else {
                    $provisional = $this->calculator->provisional($base, 0.0);
                    $session->forceFill([
                        'travel_fee' => 0,
                        'travel_distance_km' => null,
                        'admin_margin_amount' => (float) $provisional['adminMargin'],
                        'total_price' => round($base + (float) $provisional['adminMargin'] + (float) $session->extension_fee_total + (float) $session->cancellation_fee, 2),
                        'is_pricing_final' => false,
                    ])->saveQuietly();
                }
            }

            foreach ($booking->workerAssignments as $parentAssignment) {
                $rows = CleaningBookingSessionWorkerAssignment::query()
                    ->where('cleaning_booking_worker_assignment_id', $parentAssignment->id)
                    ->whereNotIn('status', [CleaningBookingWorkerAssignmentStatus::Cancelled->value, CleaningBookingWorkerAssignmentStatus::Rejected->value, CleaningBookingWorkerAssignmentStatus::Withdrawn->value])
                    ->get();

                $parentAssignment->forceFill([
                    'service_share_amount' => round((float) $rows->sum('service_share_amount'), 2),
                    'travel_fee' => round((float) $rows->sum('travel_fee'), 2),
                    'admin_margin_amount' => round((float) $rows->sum('admin_margin_amount'), 2),
                    'worker_amount' => round((float) $rows->sum('worker_amount'), 2),
                    'currency' => (string) config('app.currency', 'SYP'),
                ])->saveQuietly();
            }

            return $this->aggregateParent($booking->fresh(['sessions']));
        });
    }

    private function initialAssignmentStatusForSession(CleaningBookingSession $session): CleaningBookingWorkerAssignmentStatus
    {
        return match ($session->status) {
            CleaningBookingSessionStatus::AwaitingStartVerification => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
            CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation => CleaningBookingWorkerAssignmentStatus::StartApproved,
            CleaningBookingSessionStatus::InProgress => CleaningBookingWorkerAssignmentStatus::InProgress,
            CleaningBookingSessionStatus::AwaitingCustomerCompletion => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
            CleaningBookingSessionStatus::TimeExtensionRequested => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
            CleaningBookingSessionStatus::Completed => CleaningBookingWorkerAssignmentStatus::Completed,
            CleaningBookingSessionStatus::Cancelled => CleaningBookingWorkerAssignmentStatus::Cancelled,
            default => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        };
    }

    private function aggregateParent(CleaningBooking $booking): CleaningBooking
    {
        $booking->loadMissing('sessions');
        $base = round((float) $booking->sessions->sum('base_price'), 2);
        $travel = round((float) $booking->sessions->sum('travel_fee'), 2);
        $admin = round((float) $booking->sessions->sum('admin_margin_amount'), 2);
        $extension = round((float) $booking->sessions->sum('extension_fee_total'), 2);
        $cancellation = round((float) $booking->sessions->sum('cancellation_fee'), 2);
        $subtotal = round((float) $booking->sessions->sum('total_price'), 2);
        $discount = max(0.0, (float) ($booking->discount_amount ?? 0));
        $hours = round((float) $booking->sessions
            ->reject(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::Cancelled)
            ->sum('duration_hours'), 2);
        $propertyDetails = is_array($booking->property_details) ? $booking->property_details : [];
        $propertyDetails['hours'] = $hours;

        $booking->forceFill([
            'property_details' => $propertyDetails,
            'estimated_hours' => $hours,
            'total_hours' => $hours,
            'base_price' => $base,
            'travel_fee' => $travel,
            'travel_distance_km' => null,
            'admin_margin_amount' => $admin,
            'extension_fee_total' => $extension,
            'cancellation_fee' => $cancellation,
            'total_price' => round(max(0.0, $subtotal - $discount), 2),
            'is_pricing_final' => $booking->sessions->isNotEmpty() && $booking->sessions->every(fn (CleaningBookingSession $session): bool => (bool) $session->is_pricing_final),
        ])->saveQuietly();

        return $booking->fresh(['sessions']);
    }
}
