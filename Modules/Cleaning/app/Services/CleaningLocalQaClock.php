<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Cleaning\Models\CleaningBookingSession;
use Throwable;

/** Only scheduling policy checks may consume this clock; OTP and accounting use real time. */
final class CleaningLocalQaClock
{
    public function permitted(?User $actor, CleaningBookingSession $session): bool
    {
        if (! config('cleaning_qa.enabled') || ! config('cleaning_qa.time_travel')
            || ! config('cleaning_qa.allow_early_actions') || ! app()->environment('local')
            || config('database.default') !== 'mysql') {
            return false;
        }

        if (! $actor instanceof User || ! $actor->exists || ! $actor->is_active
            || ! in_array((string) $actor->email, config('cleaning_qa.actors', []), true)
            || ! $session->exists) {
            return false;
        }

        $connection = DB::connection('mysql');
        $host = mb_strtolower((string) $connection->getConfig('host'));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || $connection->getDatabaseName() !== config('cleaning_qa.database')) {
            return false;
        }

        try {
            $server = $connection->selectOne('SELECT @@hostname AS server_host, DATABASE() AS database_name');
            if ($server === null || mb_strtolower((string) $server->server_host) !== mb_strtolower((string) gethostname())
                || (string) $server->database_name !== config('cleaning_qa.database')) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $worker = $actor->worker;
        if ($worker === null || ! $worker->is_active || $worker->is_suspended || ! $worker->is_verified
            || ($worker->security_deposit_status !== null && $worker->security_deposit_status !== 'active')) {
            return false;
        }

        $booking = $session->booking;

        if ($booking === null || ! in_array((string) $booking->booking_number, config('cleaning_qa.bookings', []), true)) {
            return false;
        }

        return $session->relationLoaded('workerAssignments')
            ? $session->workerAssignments->contains(fn ($assignment): bool => (int) $assignment->worker_id === (int) $worker->id && $assignment->isAccepted())
            : $session->workerAssignments()->where('worker_id', $worker->id)
                ->whereIn('status', \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::acceptedValues())->exists();
    }

    public function forScheduling(CleaningBookingSession $session, ?User $actor, string $reason): CarbonImmutable
    {
        $real = CarbonImmutable::now();
        if (! $this->permitted($actor, $session) || $session->startsAt() === null) {
            return $real;
        }

        $effective = CarbonImmutable::instance($session->startsAt())->subMinutes(30);
        Log::info('cleaning.qa.temporal_override', [
            'actor_id' => $actor->id,
            'booking_id' => $session->cleaning_booking_id,
            'session_id' => $session->id,
            'actual_at' => $real->toIso8601String(),
            'effective_at' => $effective->toIso8601String(),
            'flags' => ['enabled' => true, 'time_travel' => true, 'allow_early_actions' => true],
            'reason' => $reason,
            'before_status' => (string) ($session->status->value ?? $session->status),
            'after_status' => (string) ($session->status->value ?? $session->status),
        ]);

        return $effective;
    }
}
