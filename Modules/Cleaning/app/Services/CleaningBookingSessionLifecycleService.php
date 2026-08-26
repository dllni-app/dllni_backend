<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SOSStatus;
use App\Enums\SystemAlertStatus;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use App\Models\User;
use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CleaningBookingSessionLifecycleService
{
    private const SECURITY_CODE_TTL_MINUTES = 10;
    private const MAX_SECURITY_CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly CleaningBookingSessionStatusService $statusService,
        private readonly CleaningExtendedTimePricingService $extendedTimePricing,
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    public function startTravel(CleaningBooking $booking, CleaningBookingSession $session, Worker $worker): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking, $session, $worker): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            $assignment = $this->workerAssignment($session, $worker, true);
            $this->assertSessionStatus($session, [CleaningBookingSessionStatus::WorkerAssigned], 'Session cannot start travel in current status.');

            $startedAt = $assignment->started_travel_at ?? now();
            $assignment->forceFill(['started_travel_at' => $startedAt])->save();
            $session->forceFill(['started_travel_at' => $session->started_travel_at ?? $startedAt])->saveQuietly();

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), $worker->id, 'worker_travel_started');
        $this->notifyCustomer($updated, $session, 'cleaning.booking.worker_started_travel', 'session_worker_started_travel', $worker->id);

        return $updated;
    }

    public function updateLocation(CleaningBooking $booking, CleaningBookingSession $session, Worker $worker, float $latitude, float $longitude): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking, $session, $worker, $latitude, $longitude): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            $assignment = $this->workerAssignment($session, $worker, true);
            if ($assignment->started_travel_at === null) {
                throw new InvalidArgumentException('Worker must start travel before sending location updates.');
            }

            $assignment->forceFill([
                'last_latitude' => $latitude,
                'last_longitude' => $longitude,
                'location_updated_at' => now(),
            ])->save();

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), $worker->id, 'worker_location_updated', [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        return $updated;
    }

    public function arrive(CleaningBooking $booking, CleaningBookingSession $session, Worker $worker): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking, $session, $worker): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            $assignment = $this->workerAssignment($session, $worker, true);
            $this->assertSessionStatus($session, [
                CleaningBookingSessionStatus::WorkerAssigned,
                CleaningBookingSessionStatus::AwaitingStartVerification,
            ], 'Session is not ready for arrival.');

            if ($assignment->started_travel_at === null) {
                throw new InvalidArgumentException('Worker must start travel before marking arrival.');
            }

            $arrivedAt = $assignment->arrived_at ?? now();
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
                'arrived_at' => $arrivedAt,
            ])->save();
            $session->forceFill([
                'status' => CleaningBookingSessionStatus::AwaitingStartVerification,
                'arrived_at' => $session->arrived_at ?? $arrivedAt,
            ])->saveQuietly();

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), $worker->id, 'worker_arrived');
        $this->notifyCustomer($updated, $session, 'cleaning.booking.worker_arrived', 'session_worker_arrived', $worker->id);

        return $updated;
    }

    /** @return array{securityCode:string,expiresAt:string} */
    public function issueSecurityCode(CleaningBooking $booking, CleaningBookingSession $session, Worker $worker): array
    {
        return DB::transaction(function () use ($booking, $session, $worker): array {
            [, $session] = $this->lockPair($booking, $session);
            $assignment = $this->workerAssignment($session, $worker, true);
            $this->assertSessionStatus($session, [
                CleaningBookingSessionStatus::AwaitingStartVerification,
                CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation,
            ], 'Security code is only available for a session waiting to start.');

            if ($assignment->arrived_at === null) {
                throw new InvalidArgumentException('Worker must arrive before requesting a security code.');
            }
            if ($assignment->start_approved_at !== null || $assignment->work_started_at !== null) {
                throw new InvalidArgumentException('Work has already been approved for this worker and session.');
            }

            $code = mb_str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $hash = $this->securityCodeHash($code);
            $expiresAt = now()->addMinutes(self::SECURITY_CODE_TTL_MINUTES);

            DB::table('booking_security_codes')->updateOrInsert([
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'worker_id' => $worker->id,
                'cleaning_booking_session_id' => $session->id,
            ], [
                'code' => $hash,
                'code_hash' => $hash,
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'last_attempt_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['securityCode' => $code, 'expiresAt' => $expiresAt->toIso8601String()];
        });
    }

    public function confirmSecurityCode(CleaningBooking $booking, CleaningBookingSession $session, User $customer, string $code): CleaningBooking
    {
        $this->assertCustomer($booking, $customer);

        $workerId = null;
        $updated = DB::transaction(function () use ($booking, $session, $code, &$workerId): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            $this->assertSessionStatus($session, [
                CleaningBookingSessionStatus::AwaitingStartVerification,
                CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation,
            ], 'Session is not waiting for start verification.');

            $hash = $this->securityCodeHash($code);
            $record = DB::table('booking_security_codes')
                ->where('booking_id', $booking->id)
                ->where('booking_type', $booking->getMorphClass())
                ->where('cleaning_booking_session_id', $session->id)
                ->where(function ($query) use ($hash, $code): void {
                    $query->where('code_hash', $hash)
                        ->orWhere(function ($legacy) use ($code): void {
                            $legacy->whereNull('code_hash')->where('code', $code);
                        });
                })
                ->orderByRaw('consumed_at is null desc')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                DB::table('booking_security_codes')
                    ->where('booking_id', $booking->id)
                    ->where('booking_type', $booking->getMorphClass())
                    ->where('cleaning_booking_session_id', $session->id)
                    ->whereNull('consumed_at')
                    ->where('attempts', '<', self::MAX_SECURITY_CODE_ATTEMPTS)
                    ->increment('attempts');
                throw ValidationException::withMessages(['code' => ['Invalid security code.']]);
            }

            if ((int) ($record->attempts ?? 0) >= self::MAX_SECURITY_CODE_ATTEMPTS) {
                throw new HttpException(429, 'Too many failed verification attempts. Please try again later.');
            }
            if (now()->greaterThan(Carbon::parse((string) $record->expires_at))) {
                throw ValidationException::withMessages(['code' => ['Security code has expired.']]);
            }

            $workerId = isset($record->worker_id) ? (int) $record->worker_id : null;
            if ($workerId === null) {
                throw ValidationException::withMessages(['code' => ['Security code is not associated with a worker.']]);
            }

            $assignment = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $session->id)
                ->where('worker_id', $workerId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($assignment->arrived_at === null) {
                throw ValidationException::withMessages(['code' => ['Worker must arrive before this code can be verified.']]);
            }

            $verifiedAt = now();
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::StartApproved,
                'start_approved_at' => $assignment->start_approved_at ?? $verifiedAt,
            ])->save();
            DB::table('booking_security_codes')->where('id', $record->id)->update([
                'attempts' => ((int) $record->attempts) + 1,
                'consumed_at' => $verifiedAt,
                'last_attempt_at' => $verifiedAt,
                'updated_at' => $verifiedAt,
            ]);
            $session->forceFill([
                'status' => CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation,
                'customer_confirmed_at' => $verifiedAt,
            ])->saveQuietly();

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), $workerId, 'start_verified');
        if ($workerId !== null) {
            $this->notifications->notifyWorkerById(
                booking: $updated,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.start_verified',
                action: 'session_start_verified',
                actorRole: 'customer',
                extraData: $this->sessionContext($session),
                templateContext: ['session_id' => $session->id, 'session_sequence' => $session->sequence],
            );
        }

        return $updated;
    }

    public function startWork(CleaningBooking $booking, CleaningBookingSession $session, Worker $worker): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking, $session, $worker): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            $assignment = $this->workerAssignment($session, $worker, true);
            $this->assertSessionStatus($session, [CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation], 'Session is not ready to start work.');
            if ($assignment->start_approved_at === null) {
                throw new InvalidArgumentException('Customer must verify this worker security code before work can start.');
            }

            $startedAt = $assignment->work_started_at ?? now();
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                'work_started_at' => $startedAt,
            ])->save();

            $required = max(1, (int) ($booking->number_of_workers ?? 1));
            $started = $session->workerAssignments()
                ->where('status', CleaningBookingWorkerAssignmentStatus::InProgress->value)
                ->whereNotNull('work_started_at')
                ->lockForUpdate()
                ->count();

            if ($started >= $required) {
                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::InProgress,
                    'work_started_at' => $session->work_started_at ?? $startedAt,
                ])->saveQuietly();
            }

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), $worker->id, 'started');
        return $updated;
    }

    public function complete(CleaningBooking $booking, CleaningBookingSession $session, Worker $worker, ?string $message = null): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking, $session, $worker, $message): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            $assignment = $this->workerAssignment($session, $worker, true);
            $this->assertSessionStatus($session, [CleaningBookingSessionStatus::InProgress], 'Session must be in progress to request completion.');
            if (($assignment->status?->value ?? $assignment->status) !== CleaningBookingWorkerAssignmentStatus::InProgress->value) {
                throw new InvalidArgumentException('Worker session assignment must be in progress to request completion.');
            }

            $finishedAt = now();
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
                'work_finished_at' => $finishedAt,
                'worker_completion_message' => filled($message) ? mb_trim((string) $message) : null,
            ])->save();

            $required = max(1, (int) ($booking->number_of_workers ?? 1));
            $finished = $session->workerAssignments()
                ->whereIn('status', [
                    CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                    CleaningBookingWorkerAssignmentStatus::Completed->value,
                ])
                ->lockForUpdate()
                ->count();

            if ($finished >= $required) {
                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
                    'work_finished_at' => $finishedAt,
                ])->saveQuietly();
            }

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), $worker->id, 'awaiting_customer_completion');
        $this->notifyCustomer($updated, $session, 'cleaning.booking.completion_requested', 'session_completion_requested', $worker->id);
        return $updated;
    }

    public function confirmCompletion(CleaningBooking $booking, CleaningBookingSession $session, User $customer): CleaningBooking
    {
        $this->assertCustomer($booking, $customer);

        $updated = DB::transaction(function () use ($booking, $session): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            $this->assertSessionStatus($session, [CleaningBookingSessionStatus::AwaitingCustomerCompletion], 'Session is not waiting for completion confirmation.');

            $session->workerAssignments()
                ->where('status', CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value)
                ->update(['status' => CleaningBookingWorkerAssignmentStatus::Completed->value, 'updated_at' => now()]);
            $session->forceFill([
                'status' => CleaningBookingSessionStatus::Completed,
                'customer_confirmed_at' => now(),
                'work_finished_at' => $session->work_finished_at ?? now(),
            ])->saveQuietly();

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), null, 'completed');
        foreach ($session->workerAssignments()->pluck('worker_id') as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $updated,
                workerId: (int) $workerId,
                canonicalType: 'cleaning.booking.completion_approved',
                action: 'session_completion_approved',
                actorRole: 'customer',
                extraData: $this->sessionContext($session),
                templateContext: ['session_id' => $session->id, 'session_sequence' => $session->sequence],
            );
        }

        return $updated;
    }

    public function rejectCompletion(CleaningBooking $booking, CleaningBookingSession $session, User $customer, ?string $message = null): CleaningBooking
    {
        $this->assertCustomer($booking, $customer);

        $updated = DB::transaction(function () use ($booking, $session): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            $this->assertSessionStatus($session, [CleaningBookingSessionStatus::AwaitingCustomerCompletion], 'Session is not waiting for completion confirmation.');

            $session->workerAssignments()
                ->where('status', CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value)
                ->update([
                    'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
                    'work_finished_at' => null,
                    'updated_at' => now(),
                ]);
            $session->forceFill([
                'status' => CleaningBookingSessionStatus::InProgress,
                'work_finished_at' => null,
            ])->saveQuietly();

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), null, 'completion_rejected', ['message' => $message]);
        foreach ($session->workerAssignments()->pluck('worker_id') as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $updated,
                workerId: (int) $workerId,
                canonicalType: 'cleaning.booking.completion_rejected',
                action: 'session_completion_rejected',
                actorRole: 'customer',
                extraData: array_merge($this->sessionContext($session), ['message' => $message]),
                templateContext: ['session_id' => $session->id, 'session_sequence' => $session->sequence],
            );
        }

        return $updated;
    }

    /** @return array{booking:CleaningBooking,warning:CleaningTimeWarning,extensionPricing:array<string,mixed>} */
    public function requestExtension(CleaningBooking $booking, CleaningBookingSession $session, User $customer, int $additionalMinutes, ?string $message = null, ?int $workerId = null): array
    {
        $this->assertCustomer($booking, $customer);
        $quote = $this->extendedTimePricing->quoteForBooking($booking, $additionalMinutes);

        $result = DB::transaction(function () use ($booking, $session, $additionalMinutes, $message, $workerId, $quote): array {
            [$booking, $session] = $this->lockPair($booking, $session);
            $this->assertSessionStatus($session, [CleaningBookingSessionStatus::AwaitingCustomerCompletion], 'Session is not waiting for completion confirmation.');

            $assignmentQuery = $session->workerAssignments()
                ->where('status', CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value);
            if ($workerId !== null) {
                $assignmentQuery->where('worker_id', $workerId);
            }
            $assignment = $assignmentQuery->lockForUpdate()->first();
            if (! $assignment instanceof CleaningBookingSessionWorkerAssignment) {
                throw ValidationException::withMessages(['workerId' => ['No pending worker completion exists for this session.']]);
            }

            $warning = CleaningTimeWarning::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'cleaning_booking_session_id' => $session->id,
                'worker_id' => $assignment->worker_id,
                'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
                'customer_message' => $message,
                'sent_at' => now(),
                'customer_responded_at' => now(),
                'additional_minutes' => $additionalMinutes,
                'quoted_base_amount' => $quote['baseAmount'],
                'quoted_admin_margin_amount' => $quote['adminMargin'],
                'quoted_amount' => $quote['calculatedExtensionPrice'],
                'quoted_currency' => $quote['currency'],
            ]);

            $assignment->forceFill(['status' => CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested])->save();
            $session->forceFill(['status' => CleaningBookingSessionStatus::TimeExtensionRequested])->saveQuietly();
            $booking = $this->statusService->refreshParent($booking);

            return ['booking' => $booking, 'warning' => $warning];
        });

        $this->broadcast($result['booking'], $session->fresh(), (int) $result['warning']->worker_id, 'extension_requested', [
            'warningId' => $result['warning']->id,
            'additionalMinutes' => $additionalMinutes,
        ]);
        $this->notifications->notifyWorkerById(
            booking: $result['booking'],
            workerId: (int) $result['warning']->worker_id,
            canonicalType: 'cleaning.booking.time_extension_requested',
            action: 'session_time_extension_requested',
            actorRole: 'customer',
            extraData: array_merge($this->sessionContext($session), ['warningId' => $result['warning']->id]),
            templateContext: ['session_id' => $session->id, 'session_sequence' => $session->sequence],
        );

        return [...$result, 'extensionPricing' => $quote];
    }

    public function acceptExtension(CleaningTimeWarning $warning, Worker $worker): CleaningBooking
    {
        if ($warning->cleaning_booking_session_id === null) {
            throw new InvalidArgumentException('Time warning is not session scoped.');
        }

        $updated = DB::transaction(function () use ($warning, $worker): CleaningBooking {
            $warning = CleaningTimeWarning::query()->whereKey($warning->id)->lockForUpdate()->firstOrFail();
            $booking = CleaningBooking::query()->whereKey($warning->booking_id)->lockForUpdate()->firstOrFail();
            $session = CleaningBookingSession::query()->whereKey($warning->cleaning_booking_session_id)->lockForUpdate()->firstOrFail();
            $this->assertPair($booking, $session);
            if ((int) $warning->worker_id !== (int) $worker->id) {
                abort(403, 'Extension request is not for your worker assignment.');
            }
            if ($warning->worker_responded_at !== null) {
                return $this->statusService->refreshParent($booking);
            }

            $assignment = $this->workerAssignment($session, $worker, true);
            $base = (float) ($warning->quoted_base_amount ?? 0);
            $admin = (float) ($warning->quoted_admin_margin_amount ?? 0);
            $total = (float) ($warning->quoted_amount ?? ($base + $admin));
            $minutes = max(0, (int) ($warning->additional_minutes ?? 0));

            $warning->forceFill([
                'worker_response' => CleaningTimeWarningResponse::ExtendTime,
                'worker_responded_at' => now(),
                'price_applied_at' => $warning->price_applied_at ?? now(),
            ])->save();

            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                'work_finished_at' => null,
                'service_share_amount' => round((float) $assignment->service_share_amount + $base, 2),
                'admin_margin_amount' => round((float) $assignment->admin_margin_amount + $admin, 2),
                'worker_amount' => round((float) $assignment->worker_amount + max(0.0, $base - $admin), 2),
            ])->save();
            $session->forceFill([
                'status' => CleaningBookingSessionStatus::InProgress,
                'work_finished_at' => null,
                'duration_hours' => round((float) $session->duration_hours + ($minutes / 60), 2),
                'extension_fee_total' => round((float) $session->extension_fee_total + $total, 2),
                'admin_margin_amount' => round((float) $session->admin_margin_amount + $admin, 2),
                'total_price' => round((float) $session->total_price + $total, 2),
            ])->saveQuietly();
            $this->aggregateSessionFinancials($booking);

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $warning->session()->first(), $worker->id, 'extension_accepted', ['warningId' => $warning->id]);
        return $updated;
    }

    public function rejectExtension(CleaningTimeWarning $warning, Worker $worker, ?string $message = null): CleaningBooking
    {
        if ($warning->cleaning_booking_session_id === null) {
            throw new InvalidArgumentException('Time warning is not session scoped.');
        }

        $updated = DB::transaction(function () use ($warning, $worker, $message): CleaningBooking {
            $warning = CleaningTimeWarning::query()->whereKey($warning->id)->lockForUpdate()->firstOrFail();
            $booking = CleaningBooking::query()->whereKey($warning->booking_id)->lockForUpdate()->firstOrFail();
            $session = CleaningBookingSession::query()->whereKey($warning->cleaning_booking_session_id)->lockForUpdate()->firstOrFail();
            $this->assertPair($booking, $session);
            if ((int) $warning->worker_id !== (int) $worker->id) {
                abort(403, 'Extension request is not for your worker assignment.');
            }
            if ($warning->worker_responded_at !== null) {
                return $this->statusService->refreshParent($booking);
            }

            $warning->forceFill([
                'worker_response' => CleaningTimeWarningResponse::CommitCurrentTime,
                'worker_responded_at' => now(),
                'worker_reject_message' => $message,
            ])->save();
            $assignment = $this->workerAssignment($session, $worker, true);
            $assignment->forceFill(['status' => CleaningBookingWorkerAssignmentStatus::Completed])->save();

            $pending = $session->workerAssignments()
                ->whereNotIn('status', [CleaningBookingWorkerAssignmentStatus::Completed->value, CleaningBookingWorkerAssignmentStatus::Cancelled->value])
                ->exists();
            if (! $pending) {
                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::Completed,
                    'customer_confirmed_at' => now(),
                    'work_finished_at' => $session->work_finished_at ?? now(),
                ])->saveQuietly();
            } else {
                $session->forceFill(['status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion])->saveQuietly();
            }

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $warning->session()->first(), $worker->id, 'extension_rejected', ['warningId' => $warning->id]);
        return $updated;
    }

    public function cancelSession(CleaningBooking $booking, CleaningBookingSession $session, string $role, ?string $reason = null, float $fee = 0): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking, $session, $role, $reason, $fee): CleaningBooking {
            [$booking, $session] = $this->lockPair($booking, $session);
            if (in_array($session->status, [CleaningBookingSessionStatus::Completed, CleaningBookingSessionStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['session' => ['Completed or cancelled sessions cannot be cancelled.']]);
            }
            if ($session->status === CleaningBookingSessionStatus::InProgress) {
                throw ValidationException::withMessages(['session' => ['An in-progress session cannot be cancelled from this action.']]);
            }

            $session->workerAssignments()
                ->whereNotIn('status', [CleaningBookingWorkerAssignmentStatus::Completed->value])
                ->update(['status' => CleaningBookingWorkerAssignmentStatus::Cancelled->value, 'updated_at' => now()]);
            $session->forceFill([
                'status' => CleaningBookingSessionStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by_role' => $role,
                'base_price' => 0,
                'travel_fee' => 0,
                'travel_distance_km' => null,
                'admin_margin_amount' => 0,
                'extension_fee_total' => 0,
                'cancellation_fee' => max(0, $fee),
                'total_price' => max(0, $fee),
                'is_pricing_final' => true,
            ])->saveQuietly();
            $this->aggregateSessionFinancials($booking);

            return $this->statusService->refreshParent($booking);
        });

        $this->broadcast($updated, $session->fresh(), null, 'cancelled', ['cancelledByRole' => $role, 'reason' => $reason]);
        return $updated;
    }

    public function cancelRemainingSessions(CleaningBooking $booking, string $role, ?string $reason = null, float $feePerSession = 0): CleaningBooking
    {
        $booking->loadMissing('sessions');
        $updated = $booking;

        foreach ($booking->sessions as $session) {
            if (in_array($session->status, [CleaningBookingSessionStatus::Completed, CleaningBookingSessionStatus::Cancelled], true)) {
                continue;
            }
            if ($session->status === CleaningBookingSessionStatus::InProgress) {
                continue;
            }
            $updated = $this->cancelSession($updated, $session, $role, $reason, $feePerSession);
        }

        return $updated->fresh(['sessions.workerAssignments']);
    }

    public function createSos(CleaningBooking $booking, CleaningBookingSession $session, Worker $worker, array $payload): SosAlert
    {
        return DB::transaction(function () use ($booking, $session, $worker, $payload): SosAlert {
            [, $session] = $this->lockPair($booking, $session);
            $this->workerAssignment($session, $worker, true);

            $existing = SosAlert::query()
                ->where('user_id', $worker->user_id)
                ->where('booking_id', $booking->id)
                ->where('booking_type', $booking->getMorphClass())
                ->where('cleaning_booking_session_id', $session->id)
                ->whereIn('status', [SOSStatus::Triggered->value, SOSStatus::Acknowledged->value])
                ->latest('id')
                ->first();
            if ($existing instanceof SosAlert) {
                return $existing;
            }

            $sos = SosAlert::query()->create([
                'user_id' => $worker->user_id,
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'cleaning_booking_session_id' => $session->id,
                'emergency_type' => $payload['emergency_type'] ?? null,
                'message' => $payload['message'] ?? null,
                'source' => 'booking_session',
                'status' => SOSStatus::Triggered->value,
                'latitude' => $payload['latitude'] ?? null,
                'longitude' => $payload['longitude'] ?? null,
                'triggered_at' => now(),
            ]);

            SystemAlert::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'alert_type' => AlertType::SOSTriggered->value,
                'severity' => AlertSeverity::Critical->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => array_merge($this->sessionContext($session), [
                    'source' => 'cleaning_worker_session_sos',
                    'sos_alert_id' => $sos->id,
                    'worker_id' => $worker->id,
                    'message' => $payload['message'] ?? null,
                    'emergency_type' => $payload['emergency_type'] ?? null,
                    'latitude' => $payload['latitude'] ?? null,
                    'longitude' => $payload['longitude'] ?? null,
                ]),
            ]);

            return $sos;
        });
    }

    /** @return array{0:CleaningBooking,1:CleaningBookingSession} */
    private function lockPair(CleaningBooking $booking, CleaningBookingSession $session): array
    {
        $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $session = CleaningBookingSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
        $this->assertPair($booking, $session);
        return [$booking, $session];
    }

    private function assertPair(CleaningBooking $booking, CleaningBookingSession $session): void
    {
        if ((int) $session->cleaning_booking_id !== (int) $booking->id || ! $booking->isEventAssistanceBooking()) {
            abort(404);
        }
    }

    private function assertCustomer(CleaningBooking $booking, User $customer): void
    {
        if ((int) $booking->customer_id !== (int) $customer->id) {
            abort(403, 'Booking does not belong to authenticated customer.');
        }
    }

    private function workerAssignment(CleaningBookingSession $session, Worker $worker, bool $lock = false): CleaningBookingSessionWorkerAssignment
    {
        $query = CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_session_id', $session->id)
            ->where('worker_id', $worker->id)
            ->whereNotIn('status', [
                CleaningBookingWorkerAssignmentStatus::Rejected->value,
                CleaningBookingWorkerAssignmentStatus::Withdrawn->value,
                CleaningBookingWorkerAssignmentStatus::Cancelled->value,
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $assignment = $query->first();
        if (! $assignment instanceof CleaningBookingSessionWorkerAssignment) {
            abort(403, 'Worker is not assigned to this event session.');
        }
        return $assignment;
    }

    /** @param array<int, CleaningBookingSessionStatus> $statuses */
    private function assertSessionStatus(CleaningBookingSession $session, array $statuses, string $message): void
    {
        if (! in_array($session->status, $statuses, true)) {
            throw new InvalidArgumentException($message);
        }
    }

    private function aggregateSessionFinancials(CleaningBooking $booking): void
    {
        $sessions = CleaningBookingSession::query()->where('cleaning_booking_id', $booking->id)->get();
        $base = (float) $sessions->sum('base_price');
        $travel = (float) $sessions->sum('travel_fee');
        $admin = (float) $sessions->sum('admin_margin_amount');
        $extension = (float) $sessions->sum('extension_fee_total');
        $cancellation = (float) $sessions->sum('cancellation_fee');
        $total = (float) $sessions->sum('total_price');
        $hours = (float) $sessions->reject(fn (CleaningBookingSession $session): bool => $session->status === CleaningBookingSessionStatus::Cancelled)->sum('duration_hours');
        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $details['hours'] = round($hours, 2);

        $booking->forceFill([
            'property_details' => $details,
            'estimated_hours' => round($hours, 2),
            'total_hours' => round($hours, 2),
            'base_price' => round($base, 2),
            'travel_fee' => round($travel, 2),
            'admin_margin_amount' => round($admin, 2),
            'extension_fee_total' => round($extension, 2),
            'cancellation_fee' => round($cancellation, 2),
            'total_price' => round(max(0, $total - (float) ($booking->discount_amount ?? 0)), 2),
        ])->saveQuietly();
    }

    /** @return array<string,mixed> */
    private function sessionContext(CleaningBookingSession $session): array
    {
        return [
            'sessionId' => $session->id,
            'sessionSequence' => (int) $session->sequence,
            'sessionDate' => $session->scheduled_date?->toDateString(),
        ];
    }

    /** @param array<string,mixed> $extra */
    private function broadcast(CleaningBooking $booking, ?CleaningBookingSession $session, ?int $workerId, string $action, array $extra = []): void
    {
        if (! $session instanceof CleaningBookingSession) {
            return;
        }

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, array_merge([
            'cleaningBookingId' => $booking->id,
            'bookingId' => $booking->id,
            'status' => $booking->status?->value ?? $booking->status,
            'sessionStatus' => $session->status?->value ?? $session->status,
            'workerId' => $workerId,
            'action' => $action,
            'updatedAt' => now()->toIso8601String(),
        ], $this->sessionContext($session), $extra)));
    }

    private function notifyCustomer(CleaningBooking $booking, CleaningBookingSession $session, string $canonicalType, string $action, int $workerId): void
    {
        $this->notifications->notifyCustomer(
            booking: $booking,
            canonicalType: $canonicalType,
            action: $action,
            actorRole: 'worker',
            extraData: array_merge($this->sessionContext($session), ['workerId' => $workerId]),
            templateContext: ['session_id' => $session->id, 'session_sequence' => $session->sequence],
        );
    }

    private function securityCodeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
