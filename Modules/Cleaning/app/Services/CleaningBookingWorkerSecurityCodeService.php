<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\ArrivalVerified;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CleaningBookingWorkerSecurityCodeService
{
    private const SECURITY_CODE_TTL_MINUTES = 10;

    private const SECURITY_CODE_LENGTH = 4;

    private const MAX_SECURITY_CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,
    ) {}

    /**
     * @return array{securityCode:string,expiresAt:string}
     */
    public function issueForCurrentWorker(CleaningBooking $booking): array
    {
        return DB::transaction(function () use ($booking): array {
            $worker = $this->currentWorker();
            $lockedBooking = $this->lockBooking($booking);

            $this->assertBookingIsNotTerminal($lockedBooking);

            $assignment = $this->activeAssignmentForWorker($lockedBooking->id, $worker->id, true);
            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                if ($assignment->arrived_at === null) {
                    throw new InvalidArgumentException('Worker must arrive before requesting a security code.');
                }

                if ($assignment->work_started_at !== null || $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::InProgress->value) {
                    throw new InvalidArgumentException('Work has already started for this worker.');
                }
            } else {
                if ((int) $lockedBooking->worker_id !== (int) $worker->id) {
                    throw new InvalidArgumentException('Worker must accept the booking before requesting a security code.');
                }

                if ($lockedBooking->arrived_at === null) {
                    throw new InvalidArgumentException('Worker must arrive before requesting a security code.');
                }
            }

            $generated = $this->uniqueSecurityCodeForBooking($lockedBooking);
            $expiresAt = now()->addMinutes(self::SECURITY_CODE_TTL_MINUTES);

            DB::table('booking_security_codes')->updateOrInsert(
                [
                    'booking_id' => $lockedBooking->id,
                    'booking_type' => $lockedBooking->getMorphClass(),
                    'worker_id' => $worker->id,
                ],
                [
                    'code' => $generated['hash'],
                    'code_hash' => $generated['hash'],
                    'attempts' => 0,
                    'expires_at' => $expiresAt,
                    'consumed_at' => null,
                    'last_attempt_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return [
                'securityCode' => $generated['code'],
                'expiresAt' => $expiresAt->toIso8601String(),
            ];
        });
    }

    public function confirmForCustomer(CleaningBooking $booking, string $code): CleaningBooking
    {
        if (in_array($booking->status, [
            CleaningBookingStatus::Completed,
            CleaningBookingStatus::Cancelled,
            CleaningBookingStatus::UnderDispute,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Order is not waiting for start verification.'],
            ]);
        }

        return DB::transaction(function () use ($booking, $code): CleaningBooking {
            $lockedBooking = $this->lockBooking($booking);
            $this->assertBookingIsNotTerminal($lockedBooking);

            $providedHash = $this->securityCodeHash($code);
            $record = $this->matchingSecurityCodeRecord($lockedBooking, $providedHash, $code);

            if (! $record) {
                $this->incrementActiveAttempts($lockedBooking);

                throw ValidationException::withMessages([
                    'code' => ['Invalid security code.'],
                ]);
            }

            if (($record->consumed_at ?? null) !== null) {
                return $this->freshBooking($lockedBooking);
            }

            if ((int) ($record->attempts ?? 0) >= self::MAX_SECURITY_CODE_ATTEMPTS) {
                throw new HttpException(429, 'Too many failed verification attempts. Please try again later.');
            }

            if (now()->greaterThan(Carbon::parse((string) $record->expires_at))) {
                throw ValidationException::withMessages([
                    'code' => ['Security code has expired.'],
                ]);
            }

            $workerId = $this->resolveWorkerIdForSecurityCode($lockedBooking, $record);
            if ($workerId === null) {
                throw ValidationException::withMessages([
                    'code' => ['Security code is not available for this worker. Please request a new code.'],
                ]);
            }

            $assignment = $this->activeAssignmentForWorker($lockedBooking->id, $workerId, true);
            $verifiedAt = now();
            $arrivedAt = $lockedBooking->arrived_at;

            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                if ($assignment->arrived_at === null) {
                    throw ValidationException::withMessages([
                        'code' => ['Worker must arrive before this security code can be verified.'],
                    ]);
                }

                $arrivedAt = $assignment->arrived_at;
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::StartApproved,
                    'start_approved_at' => $assignment->start_approved_at ?? $verifiedAt,
                ])->save();
            } elseif ((int) $lockedBooking->worker_id !== (int) $workerId) {
                throw ValidationException::withMessages([
                    'code' => ['Security code is not available for this worker. Please request a new code.'],
                ]);
            }

            DB::table('booking_security_codes')
                ->where('id', $record->id)
                ->update([
                    'attempts' => ((int) $record->attempts) + 1,
                    'consumed_at' => $verifiedAt,
                    'last_attempt_at' => $verifiedAt,
                    'updated_at' => $verifiedAt,
                ]);

            $lockedBooking->forceFill([
                'status' => $this->resolveBookingStatusAfterVerification($lockedBooking),
                'work_started_at' => null,
                'customer_confirmed_at' => $verifiedAt,
            ])->save();

            $updated = $this->freshBooking($lockedBooking);
            $this->dispatchTrackingUpdate($updated);
            BroadcastAfterResponse::send(new ArrivalVerified(
                $updated->id,
                $workerId,
                (string) $arrivedAt?->toIso8601String(),
                (string) $updated->status?->value,
            ));
            $this->lifecycleNotifications->notifyWorker(
                booking: $updated,
                canonicalType: 'cleaning.booking.start_verified',
                action: 'start_verified',
                actorRole: 'customer',
                fromStatus: CleaningBookingStatus::AwaitingStartVerification->value,
                occurredAt: $verifiedAt->toIso8601String(),
            );

            return $updated;
        });
    }

    public function assertWorkerSecurityCodeVerified(CleaningBooking $booking, Worker $worker): void
    {
        $assignment = $this->activeAssignmentForWorker($booking->id, $worker->id, true);

        if ($assignment instanceof CleaningBookingWorkerAssignment && $assignment->start_approved_at !== null) {
            return;
        }

        $query = DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->whereNotNull('consumed_at')
            ->orderByDesc('id')
            ->lockForUpdate();

        if (max(1, (int) ($booking->number_of_workers ?? 1)) > 1) {
            $query->where('worker_id', $worker->id);
        } else {
            $query->where(function ($scope) use ($worker): void {
                $scope->where('worker_id', $worker->id)
                    ->orWhereNull('worker_id');
            });
        }

        if (! $query->first()) {
            throw new InvalidArgumentException('Customer must verify the security code before work can start.');
        }
    }

    private function matchingSecurityCodeRecord(CleaningBooking $booking, string $providedHash, string $code): ?object
    {
        return DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->where(function ($query) use ($providedHash, $code): void {
                $query->where('code_hash', $providedHash)
                    ->orWhere(function ($legacy) use ($code): void {
                        $legacy->whereNull('code_hash')
                            ->where('code', $code);
                    });
            })
            ->orderByRaw('consumed_at is null desc')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function incrementActiveAttempts(CleaningBooking $booking): void
    {
        DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_SECURITY_CODE_ATTEMPTS)
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function resolveWorkerIdForSecurityCode(CleaningBooking $booking, object $record): ?int
    {
        if (($record->worker_id ?? null) !== null) {
            return (int) $record->worker_id;
        }

        if (max(1, (int) ($booking->number_of_workers ?? 1)) > 1) {
            return null;
        }

        if ($booking->worker_id !== null) {
            return (int) $booking->worker_id;
        }

        $assignment = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->first();

        return $assignment instanceof CleaningBookingWorkerAssignment ? (int) $assignment->worker_id : null;
    }

    private function resolveBookingStatusAfterVerification(CleaningBooking $booking): CleaningBookingStatus
    {
        $activeAssignments = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->lockForUpdate()
            ->get();

        if ($activeAssignments->isEmpty()) {
            return CleaningBookingStatus::AwaitingWorkerStartConfirmation;
        }

        $hasArrivedWorkerWaitingForCode = $activeAssignments->contains(function (CleaningBookingWorkerAssignment $assignment): bool {
            return $assignment->arrived_at !== null
                && $assignment->start_approved_at === null
                && ! in_array($this->assignmentStatus($assignment), [
                    CleaningBookingWorkerAssignmentStatus::StartApproved->value,
                    CleaningBookingWorkerAssignmentStatus::InProgress->value,
                    CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                    CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value,
                ], true);
        });

        if ($hasArrivedWorkerWaitingForCode) {
            return CleaningBookingStatus::AwaitingStartVerification;
        }

        $startedWorkers = $activeAssignments->filter(function (CleaningBookingWorkerAssignment $assignment): bool {
            return $this->assignmentStatus($assignment) === CleaningBookingWorkerAssignmentStatus::InProgress->value
                && $assignment->work_started_at !== null;
        })->count();

        return $startedWorkers >= max(1, (int) ($booking->number_of_workers ?? 1))
            ? CleaningBookingStatus::InProgress
            : CleaningBookingStatus::AwaitingWorkerStartConfirmation;
    }

    /**
     * @return array{code:string,hash:string}
     */
    private function uniqueSecurityCodeForBooking(CleaningBooking $booking): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = mb_str_pad((string) random_int(0, 9999), self::SECURITY_CODE_LENGTH, '0', STR_PAD_LEFT);
            $hash = $this->securityCodeHash($code);

            $exists = DB::table('booking_security_codes')
                ->where('booking_id', $booking->id)
                ->where('booking_type', $booking->getMorphClass())
                ->where('code_hash', $hash)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->exists();

            if (! $exists) {
                return ['code' => $code, 'hash' => $hash];
            }
        }

        throw new InvalidArgumentException('Unable to generate a unique security code. Please try again.');
    }

    private function activeAssignmentForWorker(int $bookingId, int $workerId, bool $lock = false): ?CleaningBookingWorkerAssignment
    {
        $query = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $bookingId)
            ->where('worker_id', $workerId)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function currentWorker(): Worker
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            throw new InvalidArgumentException('User must have an associated worker.');
        }

        return $worker;
    }

    private function lockBooking(CleaningBooking $booking): CleaningBooking
    {
        return CleaningBooking::query()
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertBookingIsNotTerminal(CleaningBooking $booking): void
    {
        if (in_array($booking->status, [
            CleaningBookingStatus::Completed,
            CleaningBookingStatus::Cancelled,
            CleaningBookingStatus::UnderDispute,
            CleaningBookingStatus::AwaitingCustomerCompletion,
            CleaningBookingStatus::TimeExtensionRequested,
        ], true)) {
            throw new InvalidArgumentException('Security code is only available for bookings ready to start.');
        }
    }

    private function securityCodeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function assignmentStatus(CleaningBookingWorkerAssignment $assignment): string
    {
        return $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status->value
            : (string) $assignment->status;
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        $status = $booking->status instanceof CleaningBookingStatus ? $booking->status->value : (string) $booking->status;

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $status,
            'statusLabel' => $booking->status instanceof CleaningBookingStatus ? $booking->status->label() : null,
            'workerId' => $booking->worker_id,
            'assignmentMode' => $booking->resolvedAssignmentMode(),
            'requiredWorkers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            'acceptedWorkers' => $booking->acceptedWorkerCount(),
            'remainingWorkers' => $booking->remainingWorkerCount(),
            'startApprovedWorkers' => $booking->startApprovedWorkerCount(),
            'notStartApprovedWorkers' => $booking->notStartApprovedWorkerCount(),
            'isTeamFulfilled' => $booking->isTeamFulfilled(),
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'timerStoppedAt' => $booking->work_finished_at?->toIso8601String(),
            'isTimerRunning' => $status === CleaningBookingStatus::InProgress->value,
            'workerCompletionMessage' => $booking->worker_completion_message,
            'customerCompletionRejectionMessage' => $booking->customer_completion_rejection_message,
            'completionRejectedAt' => $booking->completion_rejected_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }

    private function freshBooking(CleaningBooking $booking): CleaningBooking
    {
        return $booking->fresh([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]);
    }
}
