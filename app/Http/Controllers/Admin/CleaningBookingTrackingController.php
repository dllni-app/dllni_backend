<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningBookingTrackingController
{
    public function __invoke(Request $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        abort_unless($request->user() && CleaningBookingResource::canView($cleaning_booking), 403);

        $cleaning_booking->loadMissing([
            'worker.user',
            'workerAssignments.worker.user',
        ]);

        $assignments = $cleaning_booking->workerAssignments
            ->filter(
                fn (CleaningBookingWorkerAssignment $assignment): bool => in_array(
                    $this->enumValue($assignment->status),
                    CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                    true,
                ),
            )
            ->sortBy('id')
            ->values();

        $workers = $assignments
            ->map(fn (CleaningBookingWorkerAssignment $assignment): array => $this->assignmentPayload($assignment))
            ->all();

        if ($workers === [] && $cleaning_booking->worker_id !== null) {
            $workers[] = $this->legacyWorkerPayload($cleaning_booking);
        }

        $status = $cleaning_booking->status instanceof CleaningBookingStatus
            ? $cleaning_booking->status
            : CleaningBookingStatus::tryFrom((string) $cleaning_booking->status);

        $requiredWorkers = max(1, (int) ($cleaning_booking->number_of_workers ?? 1));
        $acceptedWorkers = $cleaning_booking->acceptedWorkerCount();

        return response()->json([
            'data' => [
                'bookingId' => $cleaning_booking->id,
                'bookingNumber' => (string) $cleaning_booking->booking_number,
                'bookingStatus' => $status?->value ?? (string) $cleaning_booking->status,
                'bookingStatusLabel' => $status?->label() ?? '-',
                'acceptedWorkers' => $acceptedWorkers,
                'requiredWorkers' => $requiredWorkers,
                'remainingWorkers' => max(0, $requiredWorkers - $acceptedWorkers),
                'destination' => $this->destinationPayload($cleaning_booking),
                'workers' => $workers,
                'updatedAt' => now()->toIso8601String(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function assignmentPayload(CleaningBookingWorkerAssignment $assignment): array
    {
        $status = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status
            : CleaningBookingWorkerAssignmentStatus::tryFrom((string) $assignment->status);
        $worker = $assignment->worker;

        return [
            'assignmentId' => $assignment->id,
            'workerId' => $assignment->worker_id,
            'name' => $this->workerName($worker, $assignment->worker_id),
            'phone' => $worker?->user?->phone,
            'status' => $status?->value ?? (string) $assignment->status,
            'statusLabel' => $this->workerStatusLabel($status?->value ?? (string) $assignment->status),
            'statusTone' => $this->workerStatusTone($status?->value ?? (string) $assignment->status),
            'acceptedAt' => $assignment->accepted_at?->toIso8601String(),
            'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),
            'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
            'workStartedAt' => $assignment->work_started_at?->toIso8601String(),
            'workFinishedAt' => $assignment->work_finished_at?->toIso8601String(),
            'latitude' => $assignment->last_latitude !== null ? (float) $assignment->last_latitude : null,
            'longitude' => $assignment->last_longitude !== null ? (float) $assignment->last_longitude : null,
            'locationUpdatedAt' => $assignment->location_updated_at?->toIso8601String(),
            'isTravelling' => $assignment->started_travel_at !== null && $assignment->arrived_at === null,
        ];
    }

    /** @return array<string, mixed> */
    private function legacyWorkerPayload(CleaningBooking $booking): array
    {
        $worker = $booking->worker;
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);
        $locationUpdatedAt = $booking->getAttribute('worker_location_updated_at');

        return [
            'assignmentId' => null,
            'workerId' => (int) $booking->worker_id,
            'name' => $this->workerName($worker, (int) $booking->worker_id),
            'phone' => $worker?->user?->phone,
            'status' => $status?->value ?? (string) $booking->status,
            'statusLabel' => $status?->label() ?? '-',
            'statusTone' => $this->bookingStatusTone($status?->value ?? (string) $booking->status),
            'acceptedAt' => null,
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'latitude' => $booking->getAttribute('last_worker_latitude') !== null
                ? (float) $booking->getAttribute('last_worker_latitude')
                : null,
            'longitude' => $booking->getAttribute('last_worker_longitude') !== null
                ? (float) $booking->getAttribute('last_worker_longitude')
                : null,
            'locationUpdatedAt' => $locationUpdatedAt === null
                ? null
                : (string) $locationUpdatedAt,
            'isTravelling' => $booking->started_travel_at !== null && $booking->arrived_at === null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function destinationPayload(CleaningBooking $booking): ?array
    {
        if ($booking->address_latitude === null || $booking->address_longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $booking->address_latitude,
            'longitude' => (float) $booking->address_longitude,
            'name' => filled($booking->neighborhood_name)
                ? 'موقع العميل - '.$booking->neighborhood_name
                : 'موقع العميل',
        ];
    }

    private function workerName(?Worker $worker, int $workerId): string
    {
        return (string) ($worker?->user?->name ?: $worker?->first_name ?: 'عامل #'.$workerId);
    }

    private function workerStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'قيد الانتظار',
            'accepted' => 'مقبول',
            'accepted_waiting_for_order_start' => 'مقبول وبانتظار بدء الطلب',
            'awaiting_start_verification' => 'بانتظار التحقق من البدء',
            'start_approved' => 'تمت الموافقة على البدء',
            'in_progress' => 'قيد التنفيذ',
            'awaiting_customer_completion' => 'بانتظار تأكيد العميل',
            'time_extension_requested' => 'تم طلب تمديد الوقت',
            'completed' => 'مكتمل',
            'rejected' => 'مرفوض',
            'withdrawn' => 'منسحب',
            'cancelled' => 'ملغى',
            default => $status !== '' ? $status : '-',
        };
    }

    private function workerStatusTone(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'rejected', 'cancelled' => 'danger',
            'withdrawn', 'time_extension_requested' => 'warning',
            'in_progress' => 'primary',
            'accepted', 'accepted_waiting_for_order_start', 'awaiting_start_verification', 'start_approved', 'awaiting_customer_completion' => 'info',
            default => 'gray',
        };
    }

    private function bookingStatusTone(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'cancelled', 'under_dispute' => 'danger',
            'time_extension_requested' => 'warning',
            'in_progress' => 'primary',
            'worker_assigned', 'awaiting_start_verification', 'awaiting_worker_start_confirmation', 'awaiting_customer_completion' => 'info',
            default => 'gray',
        };
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
