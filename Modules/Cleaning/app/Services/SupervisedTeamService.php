<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class SupervisedTeamService
{
    public const KIND = 'supervised_team';

    public const ROLE = 'Cleaning Supervisor';

    public function assertSupervisor(?User $user): User
    {
        if (! $user instanceof User || ! $user->is_active || ! $user->hasRole(self::ROLE)) {
            throw new HttpResponseException(response()->json([
                'code' => 'SUPERVISOR_FORBIDDEN', 'message' => 'هذا الحساب غير مخول لتطبيق المشرف.',
            ], 403));
        }

        return $user;
    }

    public function assignedQuery(User $user): Builder
    {
        $this->assertSupervisor($user);

        return CleaningBooking::query()->where('booking_kind', self::KIND)
            ->whereExists(function ($query) use ($user): void {
                $query->selectRaw('1')->from('cleaning_supervisor_assignments as sa')
                    ->whereColumn('sa.cleaning_booking_id', 'cleaning_bookings.id')
                    ->where('sa.supervisor_user_id', $user->id)
                    ->whereNull('sa.released_at')->where('sa.active_slot', 1);
            });
    }

    public function assignedBooking(User $user, int $bookingId, bool $forUpdate = false): CleaningBooking
    {
        $query = $this->assignedQuery($user);
        $booking = ($forUpdate ? $query->lockForUpdate() : $query)->find($bookingId);
        if (! $booking) {
            throw new HttpResponseException(response()->json([
                'code' => 'BOOKING_NOT_FOUND', 'message' => 'الطلب غير موجود.',
            ], 404));
        }

        return $booking;
    }

    public function attendance(CleaningBooking $booking): array
    {
        $assignments = $booking->workerAssignments()->with('worker.user')->orderBy('id')->get();
        $accepted = $assignments->filter(fn ($assignment): bool => in_array(
            $assignment->status?->value ?? (string) $assignment->status,
            CleaningBookingWorkerAssignmentStatus::acceptedValues(), true
        ));
        $arrived = $accepted->filter(fn ($assignment): bool => $assignment->arrived_at !== null);
        $required = max(1, (int) $booking->number_of_workers);

        return [
            'requiredWorkers' => $required,
            'acceptedWorkers' => $accepted->count(),
            'arrivedWorkers' => $arrived->count(),
            'teamReady' => $arrived->count() >= $required,
            'workers' => $assignments->map(fn ($assignment): array => [
                'assignmentId' => $assignment->id,
                'workerId' => $assignment->worker_id,
                'name' => $assignment->worker?->user?->name,
                'status' => $assignment->status?->value ?? (string) $assignment->status,
                'acceptedAt' => $assignment->accepted_at?->toIso8601String(),
                'startedTravelAt' => $assignment->started_travel_at?->toIso8601String(),
                'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function summary(CleaningBooking $booking): array
    {
        $attendance = $this->attendance($booking);
        $state = (string) ($booking->supervised_state ?: 'staffing');
        $terminal = in_array($booking->status, [
            \Modules\Cleaning\Enums\CleaningBookingStatus::Completed,
            \Modules\Cleaning\Enums\CleaningBookingStatus::Cancelled,
        ], true);
        $actions = $terminal ? [] : ['report_incident', 'request_replacement'];
        if (! $terminal && $attendance['teamReady']) {
            if (in_array($state, ['staffing', 'verification_pending'], true)
                && in_array($booking->status, [
                    \Modules\Cleaning\Enums\CleaningBookingStatus::WorkerAssigned,
                    \Modules\Cleaning\Enums\CleaningBookingStatus::AwaitingStartVerification,
                ], true)) {
                $actions[] = 'request_verification';
                if ($state === 'verification_pending') {
                    $actions[] = 'confirm_verification';
                }
            }
            if ($state === 'verified') {
                $actions[] = 'start';
            }
        }
        if ($booking->status === \Modules\Cleaning\Enums\CleaningBookingStatus::InProgress
            && $state === 'in_progress') {
            $actions = array_merge($actions, ['request_extension', 'request_handover', 'add_media']);
        }
        if (in_array($state, ['handover_pending', 'handover_confirmed'], true) && ! $terminal) {
            $actions[] = 'add_media';
        }
        if ($state === 'handover_confirmed' && ! $terminal) {
            $actions[] = 'submit_report';
        }
        $minutes = max(1, (int) ($booking->supervised_duration_minutes ?: ceil(
            ((float) $booking->estimated_hours * 60) / max(1, (int) $booking->number_of_workers)
        )));
        $scheduledEnd = $booking->supervised_scheduled_end_at;
        $scheduledStart = null;
        if ($booking->scheduled_date && $booking->scheduled_time) {
            $scheduledStart = \Carbon\Carbon::parse(
                $booking->scheduled_date->format('Y-m-d').' '.$booking->scheduled_time,
                config('app.timezone')
            );
            $scheduledEnd ??= $scheduledStart->copy()->addMinutes($minutes);
        }

        return [
            'id' => $booking->id,
            'bookingNumber' => $booking->booking_number,
            'serviceType' => self::KIND,
            'status' => $booking->status?->value ?? (string) $booking->status,
            'teamState' => $state,
            'scheduledDate' => $booking->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => mb_substr((string) $booking->scheduled_time, 0, 5),
            'scheduledStartAt' => $scheduledStart?->toIso8601String(),
            'scheduledEndAt' => $scheduledEnd?->toIso8601String(),
            'originalScheduledEndAt' => $booking->supervised_original_end_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'durationMinutes' => $minutes,
            'requiredWorkers' => $attendance['requiredWorkers'],
            'acceptedWorkers' => $attendance['acceptedWorkers'],
            'arrivedWorkers' => $attendance['arrivedWorkers'],
            'teamReady' => $attendance['teamReady'],
            'serverNow' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'allowedActions' => array_values(array_unique($actions)),
        ];
    }

    public function activity(int $bookingId, int $userId, string $action, array $metadata = []): void
    {
        DB::table('cleaning_supervisor_activity')->insert([
            'cleaning_booking_id' => $bookingId,
            'actor_user_id' => $userId,
            'action' => $action,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
