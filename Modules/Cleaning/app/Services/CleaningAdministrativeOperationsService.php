<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningOpenTimeExtension;

final class CleaningAdministrativeOperationsService
{
    public function __construct(
        private readonly CleaningOpenTimeBillingService $billing,
        private readonly CleaningBookingSessionLifecycleService $sessionLifecycle,
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    public function assertOperator(User $admin): void
    {
        abort_unless($admin->hasAnyRole(['admin', 'Super Admin']) || $admin->can('bookings.update'), 403, 'Administrative cleaning permission required.');
    }

    public function terminateOpenTime(
        CleaningBooking $booking,
        User $admin,
        string $reason,
        ?CleaningBookingSession $session = null,
    ): CleaningBooking|CleaningBookingSession {
        $this->assertOperator($admin);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => ['A termination reason is required (maximum 2000 characters).']]);
        }

        $notify = false;
        $workerIds = [];
        $result = DB::transaction(function () use ($booking, $admin, $reason, $session, &$notify, &$workerIds): CleaningBooking|CleaningBookingSession {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($lockedBooking->booking_kind !== 'open_time') {
                throw ValidationException::withMessages(['booking' => ['This is not an Open-Time booking.']]);
            }

            $at = now();
            if ($session !== null) {
                $lockedSession = CleaningBookingSession::query()->whereKey($session->id)
                    ->where('cleaning_booking_id', $lockedBooking->id)
                    ->lockForUpdate()->firstOrFail();
                if ((string) $lockedSession->session_type !== CleaningBookingSession::TYPE_OPEN_TIME) {
                    throw ValidationException::withMessages(['session' => ['This is not an Open-Time session.']]);
                }
                if ($lockedSession->open_time_terminated_at !== null) {
                    return $lockedSession;
                }
                if ($lockedSession->isTerminal() || $lockedSession->work_started_at === null || $lockedSession->work_finished_at !== null) {
                    throw ValidationException::withMessages(['session' => ['Only a running Open-Time session can be terminated.']]);
                }
                $workerIds = $lockedSession->workerAssignments()
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->pluck('worker_id')->map(fn ($v): int => (int) $v)->all();
                $before = [
                    'status' => $lockedSession->status?->value ?? (string) $lockedSession->status,
                    'startedAt' => $lockedSession->work_started_at?->toIso8601String(),
                    'totalPrice' => (float) $lockedSession->total_price,
                ];
                $lockedSession->forceFill([
                    'open_time_end_status' => 'accepted',
                    'open_time_termination_reason' => $reason,
                    'open_time_terminated_at' => $at,
                    'open_time_terminated_by_id' => $admin->id,
                    'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
                    'work_finished_at' => $at,
                ])->save();

                CleaningBookingSessionWorkerAssignment::query()
                    ->where('cleaning_booking_session_id', $lockedSession->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->update([
                        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                        'work_finished_at' => $at,
                        'updated_at' => $at,
                    ]);

                $finished = $this->sessionLifecycle->confirmCompletion(
                    $lockedBooking, $lockedSession->fresh() ?? $lockedSession, 0, administrative: true
                );
                CleaningOpenTimeExtension::query()
                    ->where('cleaning_booking_session_id', $lockedSession->id)
                    ->where('status', 'pending')->update(['status' => 'expired', 'decided_at' => $at]);
                $this->audit('admin_terminate_open_time_session', $lockedBooking, $admin, $reason, $before, [
                    'sessionId' => $lockedSession->id,
                    'status' => $finished->status?->value ?? (string) $finished->status,
                    'totalPrice' => (float) $finished->total_price,
                    'terminatedAt' => $at->toIso8601String(),
                ], $lockedSession->id);
                $notify = true;
                return $finished;
            }

            if ($lockedBooking->sessions()->where('session_type', CleaningBookingSession::TYPE_OPEN_TIME)->exists()) {
                throw ValidationException::withMessages(['session' => ['Select the running Open-Time session to terminate.']]);
            }
            if ($lockedBooking->open_time_terminated_at !== null) {
                return $lockedBooking;
            }
            if ($lockedBooking->work_started_at === null || $lockedBooking->work_finished_at !== null || $lockedBooking->open_time_finalized_at !== null) {
                throw ValidationException::withMessages(['booking' => ['Only a running Open-Time booking can be terminated.']]);
            }
            $before = [
                'status' => $lockedBooking->status?->value ?? (string) $lockedBooking->status,
                'startedAt' => $lockedBooking->work_started_at?->toIso8601String(),
                'totalPrice' => (float) $lockedBooking->total_price,
            ];
            $workerIds = $lockedBooking->workerAssignments()
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->pluck('worker_id')->map(fn ($v): int => (int) $v)->all();
            if ($lockedBooking->worker_id !== null) {
                $workerIds[] = (int) $lockedBooking->worker_id;
            }
            $this->billing->prepareFinalPricing($lockedBooking, $at);
            $lockedBooking->forceFill([
                'status' => CleaningBookingStatus::Completed,
                'work_finished_at' => $at,
                'open_time_end_status' => 'accepted',
                'open_time_terminated_at' => $at,
                'open_time_terminated_by_id' => $admin->id,
                'open_time_termination_reason' => $reason,
            ])->save();
            CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->update(['status' => CleaningBookingWorkerAssignmentStatus::Completed->value, 'work_finished_at' => $at]);
            CleaningOpenTimeExtension::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('status', 'pending')->update(['status' => 'expired', 'decided_at' => $at]);
            $this->audit('admin_terminate_open_time_booking', $lockedBooking, $admin, $reason, $before, [
                'status' => 'completed',
                'totalPrice' => (float) $lockedBooking->total_price,
                'billableMinutes' => (int) $lockedBooking->open_time_billable_minutes,
                'terminatedAt' => $at->toIso8601String(),
            ]);
            $notify = true;
            return $lockedBooking->fresh() ?? $lockedBooking;
        }, 3);

        if ($notify) {
            $fresh = $booking->fresh(['customer']) ?? $booking;
            $metadata = [
                'terminationReason' => $reason,
                'terminatedBy' => 'admin',
                'sessionId' => $session?->id,
                'terminatedAt' => now()->toIso8601String(),
            ];
            $this->notifications->notifyCustomer(
                $fresh, 'cleaning.booking.updated', 'admin_terminated_open_time', 'admin',
                extraData: $metadata
            );
            foreach (array_unique($workerIds) as $workerId) {
                $this->notifications->notifyWorkerById(
                    $fresh, (int) $workerId, 'cleaning.booking.updated', 'admin_terminated_open_time', 'admin',
                    extraData: $metadata
                );
            }
        }

        return $result;
    }

    private function audit(string $action, CleaningBooking $booking, User $admin, string $reason, array $before, array $after, ?int $sessionId = null): void
    {
        DB::table('cleaning_operational_action_audits')->insert([
            'action' => $action,
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $sessionId,
            'actor_user_id' => $admin->id,
            'reason' => $reason,
            'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
