<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningOpenTimeExtension;

final class CleaningOpenTimeLifecycleService
{
    public function __construct(
        private readonly CleaningOpenTimeBillingService $billing,
        private readonly WorkerBookingScheduleConflictService $conflicts,
        private readonly CleaningBookingSessionLifecycleService $sessionLifecycle,
    ) {}

    public function meter(CleaningBooking $booking, User|Worker|null $actor = null): array
    {
        $this->assertOpenTime($booking);
        if ($actor instanceof User && (int) $booking->customer_id !== (int) $actor->id) {
            abort(403, 'Booking belongs to another customer.');
        }
        if ($actor instanceof Worker
            && (int) ($this->currentWorkerFor($booking)?->id ?? 0) !== (int) $actor->id
            && ! $this->workerAssignedToOpenTimeSession($booking, $actor)) {
            abort(403, 'Booking belongs to another worker.');
        }

        $sessions = $booking->sessions()
            ->where('session_type', CleaningBookingSession::TYPE_OPEN_TIME)
            ->orderBy('sequence')
            ->get();
        if ($sessions->isEmpty()) {
            return $this->billing->presentation($booking->fresh() ?? $booking);
        }

        $meters = $sessions
            ->map(fn (CleaningBookingSession $session): array => $this->billing->sessionPresentation($session))
            ->values()
            ->all();
        $active = collect($meters)->first(
            static fn (array $meter): bool => ! (bool) ($meter['isFinalized'] ?? false)
                && ($meter['workStartedAt'] ?? null) !== null,
        ) ?? $meters[0];

        return [
            ...$active,
            'isMultiSession' => count($meters) > 1,
            'sessionsCount' => count($meters),
            'sessions' => $meters,
        ];
    }

    public function meterSession(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        User|Worker|null $actor = null,
    ): array {
        $this->assertOpenTimeSession($booking, $session);
        if ($actor instanceof User && (int) $booking->customer_id !== (int) $actor->id) {
            abort(403, 'Booking belongs to another customer.');
        }
        if ($actor instanceof Worker && ! $this->workerAssignedToSession($session, $actor)) {
            abort(403, 'Session belongs to another worker.');
        }

        return $this->billing->sessionPresentation($session->fresh() ?? $session);
    }

    public function requestExtension(
        CleaningBooking $booking,
        User $customer,
        int $minutes,
        ?string $idempotencyKey,
    ): CleaningOpenTimeExtension {
        if ((int) $booking->customer_id !== (int) $customer->id) {
            abort(403, 'Booking belongs to another customer.');
        }
        if ($booking->sessions()->where('session_type', CleaningBookingSession::TYPE_OPEN_TIME)->exists()) {
            throw ValidationException::withMessages([
                'session' => ['Select the Open-Time session that should be extended.'],
            ]);
        }

        return DB::transaction(function () use ($booking, $customer, $minutes, $idempotencyKey): CleaningOpenTimeExtension {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertRunning($locked);
            $minutes = max(1, $minutes);
            $options = array_map('intval', (array) ($locked->open_time_extension_options ?? [15, 30, 60]));
            if (! in_array($minutes, $options, true)) {
                throw ValidationException::withMessages(['minutes' => ['The selected extension duration is not available.']]);
            }

            $newCeilingMinutes = (int) ($locked->open_time_expected_max_minutes ?? 480) + $minutes;
            if ($newCeilingMinutes > (int) ($locked->open_time_hard_max_minutes ?? 480)) {
                throw ValidationException::withMessages(['minutes' => ['The extension exceeds the hard maximum duration.']]);
            }

            $worker = $this->currentWorkerFor($locked);
            if (! $worker instanceof Worker) {
                throw ValidationException::withMessages(['worker' => ['No current worker is available to decide this extension.']]);
            }
            $conflicts = $this->conflicts->conflictsForDefinitions($worker, [[
                'date' => $locked->scheduled_date?->toDateString() ?? (string) $locked->scheduled_date,
                'time' => (string) $locked->scheduled_time,
                'hours' => $newCeilingMinutes / 60,
            ]], (int) $locked->id);
            if ($conflicts !== []) {
                throw ValidationException::withMessages(['minutes' => ['The current worker has a schedule conflict during this extension.']]);
            }

            $key = filled($idempotencyKey) ? mb_substr((string) $idempotencyKey, 0, 100) : null;
            if ($key !== null) {
                $existing = CleaningOpenTimeExtension::query()
                    ->where('cleaning_booking_id', $locked->id)
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing instanceof CleaningOpenTimeExtension) {
                    return $existing;
                }
            }

            CleaningOpenTimeExtension::query()
                ->where('cleaning_booking_id', $locked->id)
                ->where('status', 'pending')
                ->update(['status' => 'expired', 'decided_at' => now()]);

            return CleaningOpenTimeExtension::query()->create([
                'cleaning_booking_id' => $locked->id,
                'customer_id' => $customer->id,
                'worker_id' => $worker->id,
                'requested_minutes' => $minutes,
                'status' => 'pending',
                'idempotency_key' => $key,
            ]);
        }, 3);
    }

    public function requestSessionExtension(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        User $customer,
        int $minutes,
        ?string $idempotencyKey,
    ): CleaningOpenTimeExtension {
        if ((int) $booking->customer_id !== (int) $customer->id) {
            abort(403, 'Booking belongs to another customer.');
        }

        return DB::transaction(function () use ($booking, $session, $customer, $minutes, $idempotencyKey): CleaningOpenTimeExtension {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $lockedSession = CleaningBookingSession::query()
                ->whereKey($session->id)
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertSessionRunning($lockedBooking, $lockedSession);

            $minutes = max(1, $minutes);
            $snapshot = is_array($lockedSession->pricing_snapshot) ? $lockedSession->pricing_snapshot : [];
            $options = array_map('intval', (array) ($snapshot['extensionOptions'] ?? [15, 30, 60]));
            if (! in_array($minutes, $options, true)) {
                throw ValidationException::withMessages(['minutes' => ['The selected extension duration is not available.']]);
            }

            $expectedMinutes = max(15, (int) ($lockedSession->open_time_expected_max_minutes ?? $snapshot['expectedMaxMinutes'] ?? 480));
            $hardMaxMinutes = max($expectedMinutes, (int) ($lockedSession->open_time_hard_max_minutes ?? $snapshot['hardMaxMinutes'] ?? 480));
            $newCeilingMinutes = $expectedMinutes + $minutes;
            if ($newCeilingMinutes > $hardMaxMinutes) {
                throw ValidationException::withMessages(['minutes' => ['The extension exceeds the hard maximum duration.']]);
            }

            $worker = $this->currentWorkerForSession($lockedSession);
            if (! $worker instanceof Worker) {
                throw ValidationException::withMessages(['worker' => ['No current worker is available to decide this extension.']]);
            }
            $originalExpectedMinutes = $lockedSession->open_time_expected_max_minutes;
            $originalDurationHours = $lockedSession->duration_hours;
            $lockedSession->open_time_expected_max_minutes = $newCeilingMinutes;
            $lockedSession->duration_hours = round($newCeilingMinutes / 60, 2);
            $this->conflicts->forgetWorker($worker);
            $conflicts = $this->conflicts->conflictsForSession($worker, $lockedSession);
            $lockedSession->open_time_expected_max_minutes = $originalExpectedMinutes;
            $lockedSession->duration_hours = $originalDurationHours;
            if ($conflicts !== []) {
                throw ValidationException::withMessages(['minutes' => ['The current worker has a schedule conflict during this extension.']]);
            }

            $key = filled($idempotencyKey) ? mb_substr((string) $idempotencyKey, 0, 100) : null;
            if ($key !== null) {
                $existing = CleaningOpenTimeExtension::query()
                    ->where('cleaning_booking_id', $lockedBooking->id)
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing instanceof CleaningOpenTimeExtension) {
                    return $existing;
                }
            }

            CleaningOpenTimeExtension::query()
                ->where('cleaning_booking_session_id', $lockedSession->id)
                ->where('status', 'pending')
                ->update(['status' => 'expired', 'decided_at' => now()]);

            return CleaningOpenTimeExtension::query()->create([
                'cleaning_booking_id' => $lockedBooking->id,
                'cleaning_booking_session_id' => $lockedSession->id,
                'customer_id' => $customer->id,
                'worker_id' => $worker->id,
                'requested_minutes' => $minutes,
                'status' => 'pending',
                'idempotency_key' => $key,
                'conflict_snapshot' => $conflicts,
            ]);
        }, 3);
    }

    public function decideExtension(
        CleaningOpenTimeExtension $extension,
        Worker $worker,
        string $decision,
        ?string $reason,
    ): CleaningOpenTimeExtension {
        if ($extension->cleaning_booking_session_id !== null) {
            return $this->decideSessionExtension($extension, $worker, $decision, $reason);
        }

        return DB::transaction(function () use ($extension, $worker, $decision, $reason): CleaningOpenTimeExtension {
            $lockedExtension = CleaningOpenTimeExtension::query()->whereKey($extension->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedExtension->worker_id !== (int) $worker->id) {
                abort(403, 'This extension belongs to another worker.');
            }
            if ($lockedExtension->status !== 'pending') {
                return $lockedExtension;
            }

            $booking = CleaningBooking::query()->whereKey($lockedExtension->cleaning_booking_id)->lockForUpdate()->firstOrFail();
            $this->assertRunning($booking);
            $decision = $decision === 'accepted' ? 'accepted' : 'rejected';
            if ($decision === 'accepted') {
                $newMinutes = (int) ($booking->open_time_expected_max_minutes ?? 480) + (int) $lockedExtension->requested_minutes;
                if ($newMinutes > (int) ($booking->open_time_hard_max_minutes ?? 480)) {
                    throw ValidationException::withMessages(['decision' => ['The hard maximum duration has been reached.']]);
                }
                $conflicts = $this->conflicts->conflictsForDefinitions($worker, [[
                    'date' => $booking->scheduled_date?->toDateString() ?? (string) $booking->scheduled_date,
                    'time' => (string) $booking->scheduled_time,
                    'hours' => $newMinutes / 60,
                ]], (int) $booking->id);
                if ($conflicts !== []) {
                    throw ValidationException::withMessages(['decision' => ['A schedule conflict now prevents this extension.']]);
                }

                $booking->forceFill([
                    'open_time_expected_max_minutes' => $newMinutes,
                    'open_time_ceiling_ends_at' => $booking->work_started_at?->copy()->addMinutes($newMinutes),
                ])->save();
            }

            $lockedExtension->forceFill([
                'status' => $decision,
                'decision_reason' => filled($reason) ? mb_substr((string) $reason, 0, 2000) : null,
                'decided_at' => now(),
            ])->save();

            return $lockedExtension->fresh() ?? $lockedExtension;
        }, 3);
    }

    private function decideSessionExtension(
        CleaningOpenTimeExtension $extension,
        Worker $worker,
        string $decision,
        ?string $reason,
    ): CleaningOpenTimeExtension {
        return DB::transaction(function () use ($extension, $worker, $decision, $reason): CleaningOpenTimeExtension {
            $lockedExtension = CleaningOpenTimeExtension::query()->whereKey($extension->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedExtension->worker_id !== (int) $worker->id) {
                abort(403, 'This extension belongs to another worker.');
            }
            if ($lockedExtension->status !== 'pending') {
                return $lockedExtension;
            }

            $booking = CleaningBooking::query()->whereKey($lockedExtension->cleaning_booking_id)->lockForUpdate()->firstOrFail();
            $session = CleaningBookingSession::query()
                ->whereKey($lockedExtension->cleaning_booking_session_id)
                ->where('cleaning_booking_id', $booking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertSessionRunning($booking, $session);

            $decision = $decision === 'accepted' ? 'accepted' : 'rejected';
            $conflicts = [];
            if ($decision === 'accepted') {
                $snapshot = is_array($session->pricing_snapshot) ? $session->pricing_snapshot : [];
                $expectedMinutes = max(15, (int) ($session->open_time_expected_max_minutes ?? $snapshot['expectedMaxMinutes'] ?? 480));
                $hardMaxMinutes = max($expectedMinutes, (int) ($session->open_time_hard_max_minutes ?? $snapshot['hardMaxMinutes'] ?? 480));
                $newMinutes = $expectedMinutes + (int) $lockedExtension->requested_minutes;
                if ($newMinutes > $hardMaxMinutes) {
                    throw ValidationException::withMessages(['decision' => ['The hard maximum duration has been reached.']]);
                }

                $originalExpectedMinutes = $session->open_time_expected_max_minutes;
                $originalDurationHours = $session->duration_hours;
                $session->open_time_expected_max_minutes = $newMinutes;
                $session->duration_hours = round($newMinutes / 60, 2);
                $this->conflicts->forgetWorker($worker);
                $conflicts = $this->conflicts->conflictsForSession($worker, $session);
                if ($conflicts !== []) {
                    $session->open_time_expected_max_minutes = $originalExpectedMinutes;
                    $session->duration_hours = $originalDurationHours;
                    throw ValidationException::withMessages(['decision' => ['A schedule conflict now prevents this extension.']]);
                }

                $session->open_time_ceiling_ends_at = $session->work_started_at?->copy()->addMinutes($newMinutes);
                $session->save();
            }

            $lockedExtension->forceFill([
                'status' => $decision,
                'decision_reason' => filled($reason) ? mb_substr((string) $reason, 0, 2000) : null,
                'conflict_snapshot' => $conflicts,
                'decided_at' => now(),
            ])->save();

            return $lockedExtension->fresh() ?? $lockedExtension;
        }, 3);
    }

    public function requestEnd(CleaningBooking $booking, User $customer): CleaningBooking
    {
        if ((int) $booking->customer_id !== (int) $customer->id) {
            abort(403, 'Booking belongs to another customer.');
        }
        if ($booking->sessions()->where('session_type', CleaningBookingSession::TYPE_OPEN_TIME)->exists()) {
            throw ValidationException::withMessages([
                'session' => ['Select the Open-Time session that should end.'],
            ]);
        }

        $this->assertOpenTime($booking);
        if (in_array($booking->open_time_end_status, ['pending', 'accepted'], true)) {
            return $booking->fresh() ?? $booking;
        }

        $this->assertRunning($booking);
        $booking->forceFill([
            'open_time_end_requested_at' => now(),
            'open_time_end_status' => 'pending',
        ])->save();

        return $booking->fresh() ?? $booking;
    }

    public function requestSessionEnd(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        User $customer,
    ): CleaningBookingSession {
        if ((int) $booking->customer_id !== (int) $customer->id) {
            abort(403, 'Booking belongs to another customer.');
        }

        return DB::transaction(function () use ($booking, $session): CleaningBookingSession {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $lockedSession = CleaningBookingSession::query()
                ->whereKey($session->id)
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOpenTimeSession($lockedBooking, $lockedSession);
            if (in_array($lockedSession->open_time_end_status, ['pending', 'accepted'], true)) {
                return $lockedSession->fresh() ?? $lockedSession;
            }

            $this->assertSessionRunning($lockedBooking, $lockedSession);

            $lockedSession->forceFill([
                'open_time_end_requested_at' => now(),
                'open_time_end_status' => 'pending',
                'open_time_termination_reason' => null,
            ])->save();

            return $lockedSession->fresh() ?? $lockedSession;
        }, 3);
    }

    public function decideEnd(CleaningBooking $booking, Worker $worker, string $decision, ?string $reason): CleaningBooking
    {
        return DB::transaction(function () use ($booking, $worker, $decision, $reason): CleaningBooking {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertOpenTime($locked);
            if ((int) ($this->currentWorkerFor($locked)?->id ?? 0) !== (int) $worker->id) {
                abort(403, 'Booking belongs to another worker.');
            }
            $decision = $decision === 'accepted' ? 'accepted' : 'rejected';
            if ($locked->open_time_end_status === $decision) {
                return $locked->fresh() ?? $locked;
            }
            if ($locked->open_time_end_status !== 'pending') {
                throw ValidationException::withMessages(['decision' => ['No end request is awaiting a decision.']]);
            }

            if ($decision !== 'accepted') {
                $locked->forceFill([
                    'open_time_end_status' => 'rejected',
                    'open_time_termination_reason' => filled($reason) ? mb_substr((string) $reason, 0, 2000) : null,
                ])->save();

                return $locked->fresh() ?? $locked;
            }

            $finishedAt = now();
            $this->billing->prepareFinalPricing($locked, $finishedAt);
            $locked->forceFill([
                'status' => CleaningBookingStatus::Completed,
                'work_finished_at' => $finishedAt,
                'customer_confirmed_at' => $finishedAt,
                'open_time_end_status' => 'accepted',
            ])->save();

            CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $locked->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->update([
                    'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
                    'work_finished_at' => $finishedAt,
                ]);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function decideSessionEnd(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        Worker $worker,
        string $decision,
        ?string $reason,
    ): CleaningBookingSession {
        return DB::transaction(function () use ($booking, $session, $worker, $decision, $reason): CleaningBookingSession {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $lockedSession = CleaningBookingSession::query()
                ->whereKey($session->id)
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOpenTimeSession($lockedBooking, $lockedSession);
            if (! $this->workerAssignedToSession($lockedSession, $worker)) {
                abort(403, 'Session belongs to another worker.');
            }
            $decision = $decision === 'accepted' ? 'accepted' : 'rejected';
            if ($lockedSession->open_time_end_status === $decision) {
                return $lockedSession->fresh() ?? $lockedSession;
            }
            if ($lockedSession->open_time_end_status !== 'pending') {
                throw ValidationException::withMessages(['decision' => ['No end request is awaiting a decision.']]);
            }
            $this->assertSessionRunning($lockedBooking, $lockedSession);

            if ($decision !== 'accepted') {
                $lockedSession->forceFill([
                    'open_time_end_status' => 'rejected',
                    'open_time_termination_reason' => filled($reason) ? mb_substr((string) $reason, 0, 2000) : null,
                ])->save();

                return $lockedSession->fresh() ?? $lockedSession;
            }

            $finishedAt = now();
            $lockedSession->forceFill([
                'open_time_end_status' => 'accepted',
                'open_time_termination_reason' => null,
                'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
                'work_finished_at' => $finishedAt,
                'customer_completed_at' => $finishedAt,
            ])->save();
            CleaningBookingSessionWorkerAssignment::query()
                ->where('cleaning_booking_session_id', $lockedSession->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->update([
                    'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                    'work_finished_at' => $finishedAt,
                    'updated_at' => $finishedAt,
                ]);

            return $this->sessionLifecycle->confirmCompletion(
                $lockedBooking,
                $lockedSession->fresh() ?? $lockedSession,
                (int) $lockedBooking->customer_id,
            );
        }, 3);
    }

    private function currentWorkerFor(CleaningBooking $booking): ?Worker
    {
        if ($booking->worker instanceof Worker) {
            return $booking->worker;
        }

        return $booking->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->with('worker')
            ->latest('accepted_at')
            ->first()?->worker;
    }

    private function currentWorkerForSession(CleaningBookingSession $session): ?Worker
    {
        return $session->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
            ->with('worker')
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->first()?->worker;
    }

    private function workerAssignedToSession(CleaningBookingSession $session, Worker $worker): bool
    {
        return $session->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();
    }

    private function workerAssignedToOpenTimeSession(CleaningBooking $booking, Worker $worker): bool
    {
        return CleaningBookingSessionWorkerAssignment::query()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->whereHas('session', static fn ($query) => $query
                ->where('cleaning_booking_id', $booking->id)
                ->where('session_type', CleaningBookingSession::TYPE_OPEN_TIME))
            ->exists();
    }

    private function assertOpenTime(CleaningBooking $booking): void
    {
        if ($booking->booking_kind !== 'open_time') {
            throw ValidationException::withMessages(['booking' => ['This action is only available for Open-Time bookings.']]);
        }
    }

    private function assertRunning(CleaningBooking $booking): void
    {
        $this->assertOpenTime($booking);
        if ($booking->work_started_at === null || $booking->open_time_finalized_at !== null) {
            throw ValidationException::withMessages(['booking' => ['The Open-Time timer is not currently running.']]);
        }
    }

    private function assertOpenTimeSession(CleaningBooking $booking, CleaningBookingSession $session): void
    {
        $this->assertOpenTime($booking);
        if ((int) $session->cleaning_booking_id !== (int) $booking->id
            || (string) $session->session_type !== CleaningBookingSession::TYPE_OPEN_TIME) {
            throw ValidationException::withMessages(['session' => ['This is not an Open-Time session for the selected booking.']]);
        }
    }

    private function assertSessionRunning(CleaningBooking $booking, CleaningBookingSession $session): void
    {
        $this->assertOpenTimeSession($booking, $session);
        if ($session->work_started_at === null || $session->work_finished_at !== null || $session->isTerminal()) {
            throw ValidationException::withMessages(['session' => ['The Open-Time session timer is not currently running.']]);
        }
    }
}
