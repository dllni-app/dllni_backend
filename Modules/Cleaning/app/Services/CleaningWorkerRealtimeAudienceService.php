<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningWorkerRealtimeAudienceService
{
    /**
     * Resolve workers that may currently have this booking visible in their app.
     *
     * New-order dispatch always includes the database notification channel, so
     * those notification rows are the most accurate record of the workers that
     * were originally told about the pending booking. Assigned/preferred workers
     * are included as a safety net for lifecycle updates after assignment.
     *
     * @return array<int, int>
     */
    public function workerIdsForBooking(int $cleaningBookingId): array
    {
        $booking = CleaningBooking::query()->find($cleaningBookingId);

        if (! $booking instanceof CleaningBooking) {
            return [];
        }

        $workerIds = collect([
            $booking->worker_id,
            $booking->preferred_worker_id,
        ])
            ->filter(static fn (mixed $workerId): bool => is_numeric($workerId) && (int) $workerId > 0)
            ->map(static fn (mixed $workerId): int => (int) $workerId);

        $workerIds = $workerIds->merge(
            $booking->workerAssignments()
                ->pluck('worker_id')
                ->map(static fn (mixed $workerId): int => (int) $workerId)
        );

        $notifiedUserIds = DB::table('notifications')
            ->where('type', NewOrderRequestNotification::class)
            ->where('notifiable_type', (new User())->getMorphClass())
            ->where(function ($query) use ($cleaningBookingId): void {
                $query
                    ->where('data->bookingId', $cleaningBookingId)
                    ->orWhere('data->orderId', $cleaningBookingId);
            })
            ->pluck('notifiable_id')
            ->map(static fn (mixed $userId): int => (int) $userId)
            ->filter(static fn (int $userId): bool => $userId > 0)
            ->unique()
            ->values();

        if ($notifiedUserIds->isNotEmpty()) {
            $workerIds = $workerIds->merge(
                Worker::query()
                    ->whereIn('user_id', $notifiedUserIds->all())
                    ->pluck('id')
                    ->map(static fn (mixed $workerId): int => (int) $workerId)
            );
        }

        return $workerIds
            ->filter(static fn (int $workerId): bool => $workerId > 0)
            ->unique()
            ->values()
            ->all();
    }
}
