<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CleaningBookingSessionLifecycleService
{
    private const SECURITY_CODE_TTL_MINUTES = 10;

    private const SECURITY_CODE_LENGTH = 4;

    private const MAX_SECURITY_CODE_ATTEMPTS = 5;

    public function startTravel(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): CleaningBookingSession {
        return DB::transaction(function () use ($booking, $session, $worker): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertSessionNotTerminal($locked);
            $assignment = $this->activeAssignment($locked, $worker, true);

            if ($assignment->started_travel_at === null) {
                $startedAt = now();
                $assignment->forceFill(['started_travel_at' => $startedAt])->save();
                if ($locked->started_travel_at === null) {
                    $locked->forceFill(['started_travel_at' => $startedAt])->save();
                }
            }

            $this->syncParentStatus($booking);

            return $this->freshSession($locked);
        });
    }

    public function arrive(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): CleaningBookingSession {
        return DB::transaction(function () use ($booking, $session, $worker): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertSessionNotTerminal($locked);
            $assignment = $this->activeAssignment($locked, $worker, true);

            if ($assignment->started_travel_at === null) {
                throw new InvalidArgumentException('Worker must start travel before marking session arrival.');
            }

            if ($assignment->arrived_at === null) {
                $arrivedAt = now();
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
                    'arrived_at' => $arrivedAt,
                    'start_approved_at' => null,
                ])->save();

                $locked->forceFill([
                    'status' => CleaningBookingSessionStatus::AwaitingStartVerification,
                    'arrived_at' => $locked->arrived_at ?? $arrivedAt,
                ])->save();
            }

            $this->syncParentStatus($booking);

            return $this->freshSession($locked);
        });
    }

    /** @return array{securityCode:string,expiresAt:string} */
    public function issueSecurityCode(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): array {
        return DB::transaction(function () use ($booking, $session, $worker): array {
            $locked = $this->lockSession($booking, $session);
            $this->assertSessionNotTerminal($locked);
            $assignment = $this->activeAssignment($locked, $worker, true);

            if ($assignment->arrived_at === null) {
                throw new InvalidArgumentException('Worker must arrive before requesting a session security code.');
            }
            if ($assignment->start_approved_at !== null || $assignment->work_started_at !== null) {
                throw new InvalidArgumentException('Session work has already been approved for this worker.');
            }

            $generated = $this->uniqueSecurityCode($locked);
            $expiresAt = now()->addMinutes(self::SECURITY_CODE_TTL_MINUTES);

            DB::table('booking_security_codes')->updateOrInsert(
                [
                    'booking_id' => $locked->id,
                    'booking_type' => $locked->getMorphClass(),
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
                ],
            );

            return [
                'securityCode' => $generated['code'],
                'expiresAt' => $expiresAt->toIso8601String(),
            ];
        });
    }

    public function confirmSecurityCode(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        string $code,
    ): CleaningBookingSession {
        $this->assertCustomerOwnsBooking($booking, $customerId);

        return DB::transaction(function () use ($booking, $session, $code): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertSessionNotTerminal($locked);
            $providedHash = $this->securityCodeHash($code);

            $record = DB::table('booking_security_codes')
                ->where('booking_id', $locked->id)
                ->where('booking_type', $locked->getMorphClass())
                ->where(function ($query) use ($providedHash, $code): void {
                    $query->where('code_hash', $providedHash)
                        ->orWhere(function ($legacy) use ($code): void {
                            $legacy->whereNull('code_hash')->where('code', $code);
                        });
                })
                ->orderByRaw('consumed_at is null desc')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                $this->incrementActiveSecurityCodeAttempts($locked);
                throw ValidationException::withMessages(['code' => ['Invalid security code.']]);
            }

            if (($record->consumed_at ?? null) !== null) {
                return $this->freshSession($locked);
            }

            if ((int) ($record->attempts ?? 0) >= self::MAX_SECURITY_CODE_ATTEMPTS) {
                throw new HttpException(429, 'Too many failed verification attempts. Please try again later.');
            }

            if (now()->greaterThan(Carbon::parse((string) $record->expires_at))) {
                throw ValidationException::withMessages(['code' => ['Security code has expired.']]);
            }

            $workerId = (int) ($record->worker_id ?? 0);
            $assignment = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->where('worker_id', $workerId)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof CleaningBookingSessionWorkerAssignment || $assignment->arrived_at === null) {
                throw ValidationException::withMessages([
                    'code' => ['Worker must arrive before this session security code can be verified.'],
                ]);
            }

            $verifiedAt = now();
            $assignment->forceFill([
                'status' => CleaningBookingWorkerAssignmentStatus::StartApproved,
                'start_approved_at' => $assignment->start_approved_at ?? $verifiedAt,
            ])->save();

            DB::table('booking_security_codes')->where('id', $record->id)->update([
                'attempts' => ((int) ($record->attempts ?? 0)) + 1,
                'consumed_at' => $verifiedAt,
                'last_attempt_at' => $verifiedAt,
                'updated_at' => $verifiedAt,
            ]);

            $approvedCount = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', [
                    CleaningBookingWorkerAssignmentStatus::StartApproved->value,
                    CleaningBookingWorkerAssignmentStatus::InProgress->value,
                    CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                    CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value,
                    CleaningBookingWorkerAssignmentStatus::Completed->value,
                ])
                ->count();

            $locked->forceFill([
                'status' => $approvedCount >= $locked->requiredWorkerCount()
                    ? CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation
                    : CleaningBookingSessionStatus::AwaitingStartVerification,
                'customer_confirmed_at' => $verifiedAt,
            ])->save();

            $this->syncParentStatus($booking);

            return $this->freshSession($locked);
        });
    }

    public function startWork(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
    ): CleaningBookingSession {
        return DB::transaction(function () use ($booking, $session, $worker): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertSessionNotTerminal($locked);
            $assignment = $this->activeAssignment($locked, $worker, true);

            if ($assignment->arrived_at === null) {
                throw new InvalidArgumentException('Worker must arrive before starting session work.');
            }
            if ($assignment->start_approved_at === null) {
                throw new InvalidArgumentException('Customer must verify the session security code before work can start.');
            }

            if ($assignment->work_started_at === null) {
                $startedAt = now();
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                    'work_started_at' => $startedAt,
                ])->save();

                if ($locked->work_started_at === null) {
                    $locked->forceFill(['work_started_at' => $startedAt])->save();
                }
            }

            $startedCount = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereNotNull('work_started_at')
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->count();

            $locked->forceFill([
                'status' => $startedCount >= $locked->requiredWorkerCount()
                    ? CleaningBookingSessionStatus::InProgress
                    : CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation,
            ])->save();

            $this->syncParentStatus($booking);

            return $this->freshSession($locked);
        });
    }

    public function requestCompletion(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
        ?string $message = null,
    ): CleaningBookingSession {
        return DB::transaction(function () use ($booking, $session, $worker, $message): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            $this->assertSessionNotTerminal($locked);
            $assignment = $this->activeAssignment($locked, $worker, true);

            if ($assignment->work_started_at === null) {
                throw new InvalidArgumentException('Session work must start before requesting completion.');
            }

            if ($assignment->work_finished_at === null) {
                $finishedAt = now();
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
                    'work_finished_at' => $finishedAt,
                    'worker_completion_message' => $this->nullableTrimmed($message),
                ])->save();
            }

            $readyCount = CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', [
                    CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                    CleaningBookingWorkerAssignmentStatus::Completed->value,
                ])
                ->count();

            $locked->forceFill([
                'status' => $readyCount >= $locked->requiredWorkerCount()
                    ? CleaningBookingSessionStatus::AwaitingCustomerCompletion
                    : CleaningBookingSessionStatus::InProgress,
                'work_finished_at' => $readyCount >= $locked->requiredWorkerCount()
                    ? ($locked->work_finished_at ?? now())
                    : null,
            ])->save();

            $this->syncParentStatus($booking);

            return $this->freshSession($locked);
        });
    }

    public function confirmCompletion(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
    ): CleaningBookingSession {
        $this->assertCustomerOwnsBooking($booking, $customerId);

        return DB::transaction(function () use ($booking, $session): CleaningBookingSession {
            $locked = $this->lockSession($booking, $session);
            if ($locked->status === CleaningBookingSessionStatus::Completed) {
                return $this->freshSession($locked);
            }
            $this->assertSessionNotTerminal($locked);

            if ($locked->status !== CleaningBookingSessionStatus::AwaitingCustomerCompletion) {
                throw new InvalidArgumentException('Session must be awaiting customer completion confirmation.');
            }

            $completedAt = now();
            CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $locked->id)
                ->whereIn('status', [
                    CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                    CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested->value,
                ])
                ->update([
                    'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
                    'work_finished_at' => DB::raw('COALESCE(work_finished_at, CURRENT_TIMESTAMP)'),
                    'updated_at' => $completedAt,
                ]);

            $locked->forceFill([
                'status' => CleaningBookingSessionStatus::Completed,
                'work_finished_at' => $locked->work_finished_at ?? $completedAt,
                'customer_completed_at' => $locked->customer_completed_at ?? $completedAt,
            ])->save();

            $this->syncParentStatus($booking);

            $bookingId = (int) $booking->id;
            $sessionId = (int) $locked->id;
            DB::afterCommit(static function () use ($bookingId, $sessionId): void {
                $freshBooking = CleaningBooking::query()->with('customer')->find($bookingId);
                $freshSession = CleaningBookingSession::query()->find($sessionId);

                if ($freshBooking instanceof CleaningBooking && $freshSession instanceof CleaningBookingSession) {
                    app(CleaningEventSessionNotificationService::class)
                        ->notifyCompleted($freshBooking, $freshSession);
                }
            });

            return $this->freshSession($locked);
        });
    }

    private function lockSession(
        CleaningBooking $booking,
        CleaningBookingSession $session,
    ): CleaningBookingSession {
        $locked = CleaningBookingSession::query()
            ->whereKey($session->id)
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof CleaningBookingSession) {
            throw new InvalidArgumentException('Session does not belong to this booking.');
        }

        return $locked;
    }

    private function activeAssignment(
        CleaningBookingSession $session,
        Worker $worker,
        bool $lock = false,
    ): CleaningBookingSessionWorkerAssignment {
        $query = CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_session_id', $session->id)
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues());

        if ($lock) {
            $query->lockForUpdate();
        }

        $assignment = $query->first();
        if (! $assignment instanceof CleaningBookingSessionWorkerAssignment) {
            throw new InvalidArgumentException('Worker is not assigned to this session.');
        }

        return $assignment;
    }

    private function assertCustomerOwnsBooking(CleaningBooking $booking, int $customerId): void
    {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }
    }

    private function assertSessionNotTerminal(CleaningBookingSession $session): void
    {
        if ($session->isTerminal()) {
            throw new InvalidArgumentException('Session is already closed.');
        }
    }

    private function syncParentStatus(CleaningBooking $booking): void
    {
        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        $statuses = $sessions->map(
            static fn (CleaningBookingSession $session): string => $session->status instanceof CleaningBookingSessionStatus
                ? $session->status->value
                : (string) $session->status,
        );

        if ($statuses->every(static fn (string $status): bool => in_array($status, CleaningBookingSessionStatus::terminalValues(), true))) {
            $status = $statuses->contains(CleaningBookingSessionStatus::Completed->value)
                ? CleaningBookingStatus::Completed
                : CleaningBookingStatus::Cancelled;
        } elseif ($statuses->contains(CleaningBookingSessionStatus::UnderDispute->value)) {
            $status = CleaningBookingStatus::UnderDispute;
        } elseif ($statuses->contains(CleaningBookingSessionStatus::TimeExtensionRequested->value)) {
            $status = CleaningBookingStatus::TimeExtensionRequested;
        } elseif ($statuses->contains(CleaningBookingSessionStatus::AwaitingCustomerCompletion->value)) {
            $status = CleaningBookingStatus::AwaitingCustomerCompletion;
        } elseif ($statuses->contains(CleaningBookingSessionStatus::InProgress->value)) {
            $status = CleaningBookingStatus::InProgress;
        } elseif ($statuses->contains(CleaningBookingSessionStatus::AwaitingStartVerification->value)) {
            $status = CleaningBookingStatus::AwaitingStartVerification;
        } elseif ($statuses->contains(CleaningBookingSessionStatus::AwaitingWorkerStartConfirmation->value)) {
            $status = CleaningBookingStatus::AwaitingWorkerStartConfirmation;
        } elseif ($statuses->contains(CleaningBookingSessionStatus::WorkerAssigned->value)) {
            $status = CleaningBookingStatus::WorkerAssigned;
        } else {
            $status = CleaningBookingStatus::Pending;
        }

        $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $updates = ['status' => $status];
        if ($status === CleaningBookingStatus::Completed && $lockedBooking->work_finished_at === null) {
            $updates['work_finished_at'] = now();
        }
        $lockedBooking->forceFill($updates)->save();
    }

    /** @return array{code:string,hash:string} */
    private function uniqueSecurityCode(CleaningBookingSession $session): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = mb_str_pad((string) random_int(0, 9999), self::SECURITY_CODE_LENGTH, '0', STR_PAD_LEFT);
            $hash = $this->securityCodeHash($code);

            $exists = DB::table('booking_security_codes')
                ->where('booking_id', $session->id)
                ->where('booking_type', $session->getMorphClass())
                ->where('code_hash', $hash)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->exists();

            if (! $exists) {
                return ['code' => $code, 'hash' => $hash];
            }
        }

        throw new InvalidArgumentException('Unable to generate a unique session security code. Please try again.');
    }

    private function incrementActiveSecurityCodeAttempts(CleaningBookingSession $session): void
    {
        DB::table('booking_security_codes')
            ->where('booking_id', $session->id)
            ->where('booking_type', $session->getMorphClass())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_SECURITY_CODE_ATTEMPTS)
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function securityCodeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function freshSession(CleaningBookingSession $session): CleaningBookingSession
    {
        return $session->fresh(['workerAssignments.worker.user']);
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $trimmed = mb_trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
