<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningNewOrderNotificationStateService
{
    public function closeForFulfilledBooking(CleaningBooking $booking): void
    {
        if (! $booking->isTeamFulfilled()) {
            return;
        }

        $acceptedWorkerIds = $booking->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->pluck('worker_id')
            ->map(static fn (mixed $workerId): int => (int) $workerId)
            ->all();

        $acceptedUserIds = Worker::query()
            ->whereIn('id', $acceptedWorkerIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(static fn (mixed $userId): int => (int) $userId)
            ->all();

        $closedAt = now();
        $closedAtIso = $closedAt->toIso8601String();

        $notifications = DatabaseNotification::query()
            ->where('type', NewOrderRequestNotification::class)
            ->where(function (Builder $query) use ($booking): void {
                $bookingId = (int) $booking->id;

                $query->where('data->bookingId', $bookingId)
                    ->orWhere('data->orderId', $bookingId)
                    ->orWhere('data->data->bookingId', $bookingId)
                    ->orWhere('data->data->orderId', $bookingId);
            })
            ->get();

        foreach ($notifications as $notification) {
            if (in_array((int) $notification->notifiable_id, $acceptedUserIds, true)) {
                continue;
            }

            $payload = is_array($notification->data) ? $notification->data : [];
            $nestedData = is_array($payload['data'] ?? null) ? $payload['data'] : [];

            $state = [
                'state' => 'unavailable',
                'actionable' => false,
                'closeReason' => 'required_workers_fulfilled',
                'closedAt' => $closedAtIso,
            ];

            $payload = [...$payload, ...$state];
            $payload['data'] = [...$nestedData, ...$state];

            $notification->forceFill([
                'data' => $payload,
                'read_at' => $notification->read_at ?? $closedAt,
            ])->save();
        }
    }
}
