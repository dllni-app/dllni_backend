<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class SupervisedTeamService
{
    use SupervisedTeamLifecycleTrait;

    public const KIND = 'supervised_team';

    public const ROLE = 'Cleaning Supervisor';

    public function assertSupervisor(?User $user): User
    {
        abort_unless($user instanceof User && $user->is_active && $user->hasRole(self::ROLE), 403);

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

    public function assignedBooking(User $user, int $bookingId): CleaningBooking
    {
        return $this->assignedQuery($user)->findOrFail($bookingId);
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

        return [
            'id' => $booking->id,
            'bookingNumber' => $booking->booking_number,
            'serviceType' => self::KIND,
            'teamState' => $this->state($booking),
            'scheduledEndAt' => $booking->supervised_scheduled_end_at?->toIso8601String(),
            'originalScheduledEndAt' => $booking->supervised_original_end_at?->toIso8601String(),
            'durationMinutes' => $booking->supervised_duration_minutes,
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'status' => $booking->status?->value ?? (string) $booking->status,
            'scheduledDate' => $booking->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => substr((string) $booking->scheduled_time, 0, 5),
            'requiredWorkers' => $attendance['requiredWorkers'],
            'acceptedWorkers' => $attendance['acceptedWorkers'],
            'arrivedWorkers' => $attendance['arrivedWorkers'],
            'teamReady' => $attendance['teamReady'],
            'serverNow' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'allowedActions' => match ($this->state($booking)) {
                'staffing', 'verification_pending' => $this->ready($booking)
                    ? ['request_verification','report_incident','request_replacement']
                    : ['report_incident','request_replacement'],
                'verified' => ['start','report_incident'],
                'in_progress' => ['add_media','report_incident','request_extension','request_handover'],
                'handover_pending' => ['add_media'],
                'handover_confirmed' => ['submit_report'],
                default => [],
            },
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
