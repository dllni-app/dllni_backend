<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** G2B supervisor travel and arrival are separate from worker presence. */
final class CleaningV2SupervisorVisitAttendanceService
{
    /** @return array{startedTravelAt:string|null,arrivedAt:string|null,version:int} */
    public function transition(User $actor, CleaningBooking $booking, CleaningBookingSession $session, string $action): array
    {
        app(SupervisedTeamService::class)->assertSupervisor($actor);
        if (! in_array($action, ['start-travel', 'arrive'], true)) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $booking, $session, $action): array {
            $booking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $session = CleaningBookingSession::query()->whereKey($session->id)
                ->where('cleaning_booking_id', $booking->id)->lockForUpdate()->firstOrFail();
            if (! $booking->isV2OperationalGroup() || ! $session->isSupervisionInitialized()
                || $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
                || $session->isTerminal() || in_array($booking->status,
                    [CleaningBookingStatus::Cancelled, CleaningBookingStatus::Completed], true)) {
                throw ValidationException::withMessages(['visit' => ['visit_not_operational']]);
            }

            $supervisors = $session->supervisorAssignments()->where('active_slot', 1)
                ->whereNull('released_at')->lockForUpdate()->get();
            if ($supervisors->count() !== 1 || (int) $supervisors->first()->supervisor_user_id !== $actor->id) {
                abort(404, 'Visit not found.');
            }
            $supervisor = $supervisors->first();
            $attendance = $session->supervisorAttendances()->where('cleaning_booking_session_supervisor_assignment_id', $supervisor->id)
                ->lockForUpdate()->first();
            if ($action === 'arrive' && ($attendance === null || $attendance->started_travel_at === null)) {
                throw ValidationException::withMessages(['visit' => ['supervisor_must_start_travel_first']]);
            }
            if ($attendance === null) {
                $attendance = $session->supervisorAttendances()->create([
                    'cleaning_booking_session_supervisor_assignment_id' => $supervisor->id,
                    'started_travel_at' => now(),
                    'last_actor_user_id' => $actor->id,
                    'version' => 1,
                ]);
            } elseif ($action === 'arrive' && $attendance->arrived_at === null) {
                $attendance->forceFill([
                    'arrived_at' => now(),
                    'last_actor_user_id' => $actor->id,
                    'version' => $attendance->version + 1,
                ])->save();
            }
            return [
                'startedTravelAt' => $attendance->started_travel_at?->toIso8601String(),
                'arrivedAt' => $attendance->arrived_at?->toIso8601String(),
                'version' => (int) $attendance->version,
            ];
        });
    }
}
