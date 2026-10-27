<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningRoomSchedule;

/**
 * Real V2 visits for the supervisor app, kept separate from legacy team demos.
 * Re-running the seeder refreshes dates without producing duplicate bookings.
 */
final class CleaningSupervisorVisitScenarioSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $source = CleaningBooking::query()->where('booking_number', 'CLN-LINKED-001')->first();
        $supervisor = User::query()->where('email', CleaningSupervisorSeeder::EMAIL)->first();
        $workers = Worker::query()->whereHas('user', fn ($query) => $query
            ->whereIn('email', ['cleaning.worker@dllni.sy', 'cleaning.worker2@dllni.sy']))->get();

        if (! $source || ! $supervisor || $workers->count() !== 2) {
            throw new \RuntimeException('Seed linked cleaning demo users and bookings before supervisor V2 visits.');
        }

        DB::transaction(function () use ($source, $supervisor, $workers): void {
            // Scheduled, assigned, underway, completed, and cancelled visits.
            // Keep all dates relative to today so the fixtures never expire.
            foreach ([
                ['key' => 'SCHEDULED', 'days' => 1, 'time' => '09:00', 'booking' => 'worker_assigned', 'visit' => 'scheduled', 'accepted' => 0],
                ['key' => 'ASSIGNED', 'days' => 2, 'time' => '13:00', 'booking' => 'worker_assigned', 'visit' => 'worker_assigned', 'accepted' => 2],
                ['key' => 'IN-PROGRESS', 'days' => 0, 'time' => '08:00', 'booking' => 'in_progress', 'visit' => 'in_progress', 'accepted' => 2],
                ['key' => 'COMPLETED', 'days' => -1, 'time' => '09:00', 'booking' => 'completed', 'visit' => 'completed', 'accepted' => 2],
                ['key' => 'CANCELLED', 'days' => -2, 'time' => '13:00', 'booking' => 'cancelled', 'visit' => 'cancelled', 'accepted' => 0],
            ] as $scenario) {
                $number = 'CLN-V2-VISIT-'.$scenario['key'];
                $date = CarbonImmutable::now('Asia/Damascus')->addDays($scenario['days'])->toDateString();
                $start = CarbonImmutable::parse($date.' '.$scenario['time'], 'Asia/Damascus');
                $booking = CleaningBooking::query()->where('booking_number', $number)->first();
                if (! $booking) {
                    $booking = $source->replicate();
                }

                // Suppress real order notifications and queues for QA-only fixtures.
                CleaningBooking::withoutEvents(function () use ($booking, $source, $scenario, $number, $date): void {
                    $details = $source->property_details ?? [];
                    $details['_supervisor_pricing'] = ['hourlyRate' => 30000, 'fee' => 60000];
                    $booking->forceFill([
                        'booking_number' => $number,
                        'booking_kind' => 'standard',
                        'number_of_workers' => 2,
                        'customer_id' => $source->customer_id,
                        'status' => $scenario['booking'],
                        'scheduled_date' => $date,
                        'scheduled_time' => $scenario['time'],
                        'property_details' => $details,
                        'work_started_at' => $scenario['visit'] === 'in_progress' ? now()->subMinutes(30) : null,
                        'work_finished_at' => $scenario['visit'] === 'completed' ? now()->subDay() : null,
                    ])->save();
                });

                $room = $booking->rooms()->updateOrCreate(['room_key' => 'bedroom.qa.1'], [
                    'room_type' => 'bedroom', 'room_size' => 'medium',
                    'display_label' => 'غرفة النوم', 'weight' => 1,
                ]);
                $session = $booking->sessions()->updateOrCreate(['sequence' => 1], [
                    'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
                    'calculation_mode' => 'task', 'scheduled_date' => $date,
                    'scheduled_time' => $scenario['time'], 'duration_hours' => 2,
                    'required_workers' => 2, 'coverage_status' => $scenario['accepted'] ? 'fully_covered' : 'searching',
                    'status' => $scenario['visit'],
                    'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
                    'supervision_initialized_at' => now(),
                    'work_started_at' => $scenario['visit'] === 'in_progress' ? now()->subMinutes(30) : null,
                    'work_finished_at' => $scenario['visit'] === 'completed' ? now()->subDay() : null,
                    'is_pricing_final' => true,
                    'pricing_snapshot' => ['seed' => 'supervisor-v2-visits', 'currency' => 'SYP'],
                ]);

                CleaningRoomSchedule::query()->updateOrCreate([
                    'cleaning_booking_session_id' => $session->id,
                    'schedule_key' => 'bedroom.qa.1',
                ], [
                    'cleaning_booking_id' => $booking->id,
                    'cleaning_booking_room_id' => $room->id,
                    'visit_key' => 'visit.1', 'timezone' => 'Asia/Damascus',
                    'starts_at' => $start->utc(), 'ends_at' => $start->addHours(2)->utc(),
                    'required_workers' => 2, 'work_minutes' => 240,
                    'duration_minutes' => 120,
                ]);

                CleaningBookingSessionSupervisorAssignment::query()->updateOrCreate([
                    'cleaning_booking_session_id' => $session->id,
                    'active_slot' => 1,
                ], [
                    'supervisor_user_id' => $supervisor->id,
                    'assigned_by_user_id' => $supervisor->id,
                    'assigned_at' => now()->subDays(3),
                    'compensation_hourly_rate' => 25000,
                    'released_at' => null,
                ]);

                foreach ($workers as $worker) {
                    $isAccepted = $scenario['accepted'] > 0;
                    $hasArrived = in_array($scenario['visit'], ['in_progress', 'completed'], true);
                    $session->workerAssignments()->updateOrCreate(['worker_id' => $worker->id], [
                        'status' => $isAccepted ? ($scenario['visit'] === 'completed' ? 'completed' : 'accepted') : 'pending',
                        'accepted_at' => $isAccepted ? now()->subDays(3) : null,
                        'arrived_at' => $hasArrived ? $start : null,
                        'work_started_at' => $hasArrived ? $start : null,
                        'work_finished_at' => $scenario['visit'] === 'completed' ? $start->addHours(2) : null,
                        'service_share_amount' => 50000, 'worker_amount' => 50000, 'currency' => 'SYP',
                    ]);
                }
            }
        });
    }
}
