<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningGroupCustodyPolicy
{
    public function usesSupervisorCustody(CleaningBooking $booking): bool
    {
        return $booking->isSupervisedTeam()
            || $booking->isV2OperationalGroup()
            || ((int) $booking->number_of_workers > 1 && $booking->supervisorAssignments()
                ->whereNull('released_at')->exists());
    }

    public function assertActiveSupervisor(CleaningBooking $booking, User $supervisor): void
    {
        app(SupervisedTeamService::class)->assertSupervisor($supervisor);
        if ($booking->isV2OperationalGroup()) {
            abort(404, 'Visit-scoped inventory custody is not available for initialized V2 bookings.');
        }
        if (! $this->usesSupervisorCustody($booking) || ! $booking->supervisorAssignments()
            ->where('supervisor_user_id', $supervisor->id)->whereNull('released_at')->exists()) {
            abort(404, 'Booking inventory not found.');
        }
    }
}
