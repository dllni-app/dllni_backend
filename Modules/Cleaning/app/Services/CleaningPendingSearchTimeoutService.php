<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningNotificationDispatch;
use Throwable;

/**
 * Notify once for each expired six-hour attempt; a retry starts a new window.
 * Accepted bookings never enter this flow and keep their cancellation rules.
 */
final class CleaningPendingSearchTimeoutService
{
    public function __construct(
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    public function dispatchDue(?CarbonInterface $clock = null): int
    {
        $now = $clock instanceof CarbonInterface
            ? CarbonImmutable::instance($clock)
            : CarbonImmutable::now();
        $cutoff = $now->subHours(6);
        $sent = 0;

        CleaningBooking::query()
            ->with('customer')
            ->where('status', CleaningBookingStatus::Pending->value)
            // Historical past-due bookings are not revived by the new feature.
            ->where(static function (Builder $query) use ($now): void {
                $query->whereDate('scheduled_date', '>=', $now->toDateString())
                    ->orWhereHas('sessions', static function (Builder $sessions) use ($now): void {
                        $sessions->whereDate('scheduled_date', '>=', $now->toDateString());
                    });
            })
            ->where(static function (Builder $query) use ($cutoff): void {
                $query->where('search_started_at', '<=', $cutoff)
                    ->orWhere(static function (Builder $legacy) use ($cutoff): void {
                        $legacy->whereNull('search_started_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->chunkById(100, function ($bookings) use ($now, &$sent): void {
                foreach ($bookings as $booking) {
                    if ($booking->customer === null || ! $booking->searchWindowExpired()
                        || ! app(CleaningSearchDiscoveryWindowPolicy::class)->hasAcceptableFutureStart($booking)) {
                        continue;
                    }

                    $start = $booking->search_started_at ?? $booking->created_at;
                    if ($start === null) {
                        continue;
                    }
                    $due = $start->copy()->addHours(6);
                    $dedupe = app(CleaningSearchEscalationDedupeKey::class)
                        ->make((int) $booking->id, $start);
                    $notification = CleaningNotificationDispatch::query()->firstOrCreate(
                        ['dedupe_key' => $dedupe],
                        [
                            'cleaning_booking_id' => $booking->id,
                            'recipient_user_id' => $booking->customer_id,
                            'canonical_type' => 'cleaning.booking.search_expired',
                            'scheduled_at_snapshot' => $start,
                            'due_at' => $due,
                            'status' => 'claimed',
                            'attempts' => 1,
                        ],
                    );
                    if (! $notification->wasRecentlyCreated) {
                        if ($notification->status !== 'failed') {
                            continue;
                        }
                        $notification->forceFill([
                            'status' => 'claimed',
                            'attempts' => (int) $notification->attempts + 1,
                            'last_error' => null,
                        ])->save();
                    }

                    try {
                        $this->notifications->notifyCustomer(
                            booking: $booking,
                            canonicalType: 'cleaning.booking.search_expired',
                            action: 'search_expired',
                            actorRole: 'system',
                            fromStatus: 'pending',
                            occurredAt: $now->toIso8601String(),
                            extraData: [
                                'searchExpired' => true,
                                'retryOrCancel' => true,
                                'searchRetryCount' => (int) ($booking->search_retry_count ?? 0),
                            ],
                        );
                        $notification->forceFill([
                            'status' => 'sent',
                            'sent_at' => $now,
                            'last_error' => null,
                        ])->save();
                        $sent++;
                    } catch (Throwable $error) {
                        $notification->forceFill([
                            'status' => 'failed',
                            'last_error' => mb_substr($error->getMessage(), 0, 2000),
                        ])->save();
                        report($error);
                    }
                }
            });

        return $sent;
    }
}
