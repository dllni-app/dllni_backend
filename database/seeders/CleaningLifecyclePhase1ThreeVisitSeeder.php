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
use RuntimeException;

/**
 * Three independent eligible visits for Phase 1, explicitly synthetic test inputs.
 * This intentionally does not exercise a customer quotation or prove financial correctness.
 * The three sessions begin without accepted worker assignments; the real API owns transitions.
 */
final class CleaningLifecyclePhase1ThreeVisitSeeder extends Seeder
{
    public const BOOKING_NUMBER = 'CLN-QA-PHASE1-THREE-VISITS';

    public function run(): void
    {
        $connection = DB::connection();
        if (! app()->environment('local')
            || ! in_array((string) $connection->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || $connection->getDatabaseName() !== 'alnadha_worker_test') {
            throw new RuntimeException('Refusing QA fixture outside the authorized existing local test database.');
        }

        $source = CleaningBooking::query()->where('booking_number', 'CLN-LINKED-001')->firstOrFail();
        $supervisor = User::query()->where('email', CleaningSupervisorSeeder::EMAIL)->firstOrFail();
        $workers = Worker::query()->whereHas('user', static fn ($q) => $q
            ->whereIn('email', ['cleaning.worker@dllni.sy', 'cleaning.worker2@dllni.sy']))->get();
        if ($workers->count() !== 2) {
            throw new RuntimeException('Both seeded worker accounts are required for this fixture.');
        }

        $appointmentDates = [];
        $scheduleConflicts = app(\Modules\Cleaning\Services\WorkerBookingScheduleConflictService::class);
        for ($offset = 4; $offset < 42 && count($appointmentDates) < 3; ++$offset) {
            $day = CarbonImmutable::now('Asia/Damascus')->addDays($offset);
            foreach ([9, 10, 11, 12, 13, 14, 15, 16] as $hour) {
                $start = $day->setTime($hour, 0);
                $definitions = [['date' => $start->toDateString(), 'time' => $start->format('H:i'), 'hours' => 2]];
                if ($workers->every(static fn (Worker $worker): bool => $worker->isAvailableAt($start)
                    && ! $scheduleConflicts->hasConflictForDefinitions($worker, $definitions))) {
                    $appointmentDates[] = $start;
                    break;
                }
            }
        }
        if (count($appointmentDates) !== 3) {
            throw new RuntimeException('Cannot find three future shifts shared by both seeded workers.');
        }

        DB::transaction(static function () use ($source, $supervisor, $appointmentDates): void {
            $existing = CleaningBooking::query()->where('booking_number', self::BOOKING_NUMBER)->first();
            if ($existing !== null) {
                // The originally seeded three sessions predate the V2 supervisor
                // presenter requirements. Repair ONLY missing room schedules and
                // supervisor metadata; preserve acceptance, payouts and statuses.
                self::ensureSupervisorReadFixture($existing);
                return;
            }

            // Values are declared synthetic QA inputs, NOT authoritative catalogue quotes.
            // Per visit: base 200000 (includes supervisor service fee 60000), travel 20000,
            // margin 20000, total 240000 SYP. Parent is sum over three visits.
            $booking = $source->replicate();
            $booking->forceFill([
                'booking_number' => self::BOOKING_NUMBER,
                'booking_kind' => 'standard',
                'status' => 'pending',
                'assignment_mode' => 'open_count',
                'number_of_workers' => 2,
                'worker_scope' => CleaningBooking::WORKER_SCOPE_ANY,
                'worker_id' => null,
                'preferred_worker_id' => null,
                'specific_worker_ids' => null,
                'address_latitude' => 36.2050,
                'address_longitude' => 37.1180,
                'scheduled_date' => $appointmentDates[0]->toDateString(),
                'scheduled_time' => $appointmentDates[0]->format('H:i:s'),
                'total_hours' => 6,
                'base_price' => 600000,
                'addons_total' => 0,
                'travel_fee' => 60000,
                'admin_margin_amount' => 60000,
                'extension_fee_total' => 0,
                'cancellation_fee' => 0,
                'total_price' => 720000,
                'is_pricing_final' => true,
                'terms_accepted' => true,
                'work_started_at' => null,
                'work_finished_at' => null,
                'customer_confirmed_at' => null,
                'cancelled_at' => null,
            ]);
            CleaningBooking::withoutEvents(static fn () => $booking->save());

            foreach ($appointmentDates as $index => $start) {
                $session = new CleaningBookingSession([
                    'sequence' => $index + 1,
                    'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
                    'calculation_mode' => 'task',
                    'scheduled_date' => $start->toDateString(),
                    'scheduled_time' => $start->format('H:i:s'),
                    'duration_hours' => 2,
                    'required_workers' => 2,
                    'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
                    'supervision_initialized_at' => now(),
                    'coverage_status' => 'searching',
                    'status' => 'scheduled',
                    'base_price' => 200000,
                    'addons_total' => 0,
                    'materials_total' => 0,
                    'special_services_total' => 0,
                    'travel_fee' => 20000,
                    'admin_margin_amount' => 20000,
                    'extension_fee_total' => 0,
                    'cancellation_fee' => 0,
                    'total_price' => 240000,
                    'is_pricing_final' => true,
                    'pricing_snapshot' => [
                        'contractVersion' => 'phase1_qa_fixture',
                        'seed' => 'cleaning-lifecycle-phase1-three-visits',
                        'supervisorFeeIncluded' => 60000,
                        'supervisorCompensationHourlyRate' => 25000,
                        'currency' => 'SYP',
                    ],
                ]);
                $booking->sessions()->save($session);

                CleaningBookingSessionSupervisorAssignment::query()->create([
                    'cleaning_booking_session_id' => $session->id,
                    'active_slot' => 1,
                    'supervisor_user_id' => $supervisor->id,
                    'assigned_by_user_id' => $supervisor->id,
                    'assigned_at' => now(),
                    'compensation_hourly_rate' => 25000,
                ]);
            }

            self::ensureSupervisorReadFixture($booking);
        }, 3);
    }

    private static function ensureSupervisorReadFixture(CleaningBooking $booking): void
    {
        if ($booking->booking_number !== self::BOOKING_NUMBER) {
            throw new RuntimeException('Refusing to alter non-QA booking.');
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $details['_supervisor_pricing'] = ['hourlyRate' => 30000, 'fee' => 60000];
        $booking->forceFill(['property_details' => $details])->saveQuietly();

        // Room schedule entries represent time windows and task definitions.
        // They are NOT worker-specific room claiming/assignment records.
        $room = $booking->rooms()->firstOrCreate(['room_key' => 'bedroom.qa.1'], [
            'room_type' => 'bedroom',
            'room_size' => 'medium',
            'display_label' => 'غرفة نوم للتجربة',
            'weight' => 1,
        ]);
        foreach ($booking->sessions()->orderBy('sequence')->get() as $session) {
            $date = $session->scheduled_date?->toDateString();
            $time = mb_substr((string) $session->scheduled_time, 0, 5);
            if ($date === null || $time === '') {
                throw new RuntimeException('Test visit schedule is incomplete.');
            }
            $start = CarbonImmutable::parse($date.' '.$time, 'Asia/Damascus');
            CleaningRoomSchedule::query()->updateOrCreate([
                'cleaning_booking_session_id' => $session->id,
                'schedule_key' => 'phase1.qa.visit.'.$session->sequence,
            ], [
                'cleaning_booking_id' => $booking->id,
                'cleaning_booking_room_id' => $room->id,
                'visit_key' => 'visit.'.$session->sequence,
                'timezone' => 'Asia/Damascus',
                'starts_at' => $start->utc(),
                'ends_at' => $start->addHours(2)->utc(),
                'required_workers' => 2,
                'work_minutes' => 240,
                'duration_minutes' => 120,
            ]);
        }
    }
}
