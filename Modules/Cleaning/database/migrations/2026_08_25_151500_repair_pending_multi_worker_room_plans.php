<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingTeamService;

return new class extends Migration
{
    public function up(): void
    {
        $teamService = app(CleaningBookingTeamService::class);

        CleaningBooking::query()
            ->where('status', CleaningBookingStatus::Pending->value)
            ->where('assignment_mode', CleaningAssignmentMode::OpenCount->value)
            ->where('number_of_workers', '>', 1)
            ->whereHas('workerAssignments', function ($query): void {
                $query->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->whereHas('rooms')
            ->whereDoesntHave('rooms', function ($query): void {
                $query->whereNotNull('planned_worker_slot');
            })
            ->orderBy('id')
            ->chunkById(50, function ($bookings) use ($teamService): void {
                foreach ($bookings as $booking) {
                    $teamService->recalculateBookingTeam($booking, finalizeBooking: false);
                }
            });
    }

    public function down(): void
    {
        // Data repair is intentionally irreversible: no room IDs or explicit
        // customer/worker assignments are deleted or recreated.
    }
};
