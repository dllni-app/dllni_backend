<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Notifications\CleaningV3VisitShortageNotification;
use RuntimeException;
use Throwable;

/**
 * Transactional database-inbox delivery for V3 six-hour escalation intents.
 *
 * Each recipient inbox entry and the outbox acknowledgement commit together.
 * It is not an FCM receipt or evidence of device push delivery. Parallel
 * runners lock the same intent, and deterministic notification UUIDs prevent
 * extra logical inbox entries across retry/restart.
 */
final class CleaningV3DispatchNoticeDeliveryService
{
    /** @return array{delivered:int,cancelled:int,failed:int} */
    public function deliverPending(int $batchLimit = 100): array
    {
        $ids = DB::table('cleaning_v3_dispatch_notice_intents')
            ->whereNull('delivered_at')->whereNull('cancelled_at')
            ->orderBy('id')
            ->limit(max(1, min($batchLimit, 500)))
            ->pluck('id');

        $result = ['delivered' => 0, 'cancelled' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            try {
                $outcome = DB::transaction(function () use ($id): string {
                    $intent = DB::table('cleaning_v3_dispatch_notice_intents')
                        ->where('id', (int) $id)->lockForUpdate()->first();
                    if ($intent === null || $intent->delivered_at !== null
                        || $intent->cancelled_at !== null) {
                        return 'skipped';
                    }

                    $dispatch = DB::table('cleaning_v3_visit_dispatches')
                        ->where('id', $intent->cleaning_v3_visit_dispatch_id)->first();
                    if ($dispatch === null) {
                        throw new RuntimeException('V3_NOTICE_DISPATCH_MISSING');
                    }
                    $visit = CleaningBookingSession::query()
                        ->with('booking.customer')
                        ->find($dispatch->cleaning_booking_session_id);
                    $booking = $visit?->booking;
                    if (! $visit instanceof CleaningBookingSession
                        || ! $booking instanceof CleaningBooking
                        || (string) ($booking->contract_version ?? '') !== 'v3') {
                        throw new RuntimeException('V3_NOTICE_BOOKING_MISSING');
                    }

                    $remaining = max(0, $visit->requiredWorkerCount() -
                        $visit->workerAssignments()
                            ->whereNull('released_at')
                            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                            ->count());

                    // If coverage was restored before an intent was delivered,
                    // do not send a stale six-hour shortage notification.
                    if ($visit->isTerminal() || $remaining === 0
                        || $dispatch->resolved_at !== null) {
                        DB::table('cleaning_v3_dispatch_notice_intents')->where('id', $id)
                            ->update(['cancelled_at' => now(), 'updated_at' => now()]);

                        return 'cancelled';
                    }

                    $recipients = match ((string) $intent->recipient_kind) {
                        'customer' => $booking->customer instanceof User
                            ? collect([$booking->customer]) : collect(),
                        'admin' => DashboardAdminRecipients::all(),
                        default => throw new RuntimeException('V3_NOTICE_RECIPIENT_KIND_INVALID'),
                    };
                    if ($recipients->isEmpty()) {
                        throw new RuntimeException('V3_NOTICE_RECIPIENT_UNAVAILABLE');
                    }

                    foreach ($recipients as $recipient) {
                        Notification::sendNow(
                            $recipient,
                            new CleaningV3VisitShortageNotification(
                                booking: $booking,
                                visit: $visit,
                                remainingWorkers: $remaining,
                                recipientKind: (string) $intent->recipient_kind,
                                intentId: (int) $intent->id,
                                recipientUserId: (int) $recipient->id,
                            ),
                        );
                    }

                    // Database channel completed within this transaction:
                    // delivered_at proves persistence in user notifications
                    // only, NOT successful mobile push transport.
                    DB::table('cleaning_v3_dispatch_notice_intents')->where('id', $id)
                        ->update([
                            'delivered_at' => now(),
                            'attempts' => (int) $intent->attempts + 1,
                            'last_error' => null,
                            'updated_at' => now(),
                        ]);

                    return 'delivered';
                }, 3);

                if (isset($result[$outcome])) {
                    $result[$outcome]++;
                }
            } catch (Throwable $error) {
                // Keep the intent eligible for later retry; the transaction
                // already rolled back every partial inbox insertion.
                DB::table('cleaning_v3_dispatch_notice_intents')
                    ->where('id', (int) $id)
                    ->whereNull('delivered_at')
                    ->whereNull('cancelled_at')
                    ->increment('attempts', 1, [
                        'last_error' => mb_substr($error->getMessage(), 0, 1800),
                        'updated_at' => now(),
                    ]);
                report($error);
                $result['failed']++;
            }
        }

        return $result;
    }
}
