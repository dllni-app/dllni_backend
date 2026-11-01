<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use RuntimeException;

/**
 * One independent ordinary booking per selected appointment.
 * These synthetic local QA prices are not a substitute for the customer quote.
 */
final class CleaningIndependentOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $database = DB::connection();
        if (! app()->environment('local')
            || $database->getDriverName() !== 'mysql'
            || ! in_array((string) $database->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Independent order QA fixtures require a local MySQL database host.');
        }

        $source = CleaningBooking::query()->where('booking_number', 'CLN-LINKED-001')->firstOrFail();
        $supervisor = User::query()->where('email', CleaningSupervisorSeeder::EMAIL)->firstOrFail();

        DB::transaction(static function () use ($source, $supervisor): void {
            foreach ([4, 7, 10] as $index => $daysFromNow) {
                $appointment = CarbonImmutable::now('Asia/Damascus')->addDays($daysFromNow)->setTime(9, 0);
                $number = 'CLN-QA-INDEPENDENT-ORDER-'.($index + 1);

                $booking = CleaningBooking::query()->where('booking_number', $number)->first();
                if ($booking === null) {
                    $booking = $source->replicate();
                    $booking->forceFill([
                    'booking_number' => $number,
                    'booking_kind' => 'standard',
                    'status' => 'pending',
                    'assignment_mode' => 'open_count',
                    'worker_scope' => CleaningBooking::WORKER_SCOPE_ANY,
                    'worker_id' => null,
                    'preferred_worker_id' => null,
                    'specific_worker_ids' => null,
                    'number_of_workers' => 2,
                    // Existing QA workers are based in Aleppo. The source
                    // booking has no GPS coordinates, so dispatch rejects it.
                    'address_latitude' => 36.2168,
                    'address_longitude' => 37.1317,
                    'scheduled_date' => $appointment->toDateString(),
                    'scheduled_time' => $appointment->format('H:i:s'),
                    'estimated_hours' => 2,
                    'total_hours' => 2,
                    'base_price' => 200000,
                    'addons_total' => 0,
                    'travel_fee' => 20000,
                    'admin_margin_amount' => 20000,
                    'total_price' => 240000,
                    'subtotal_before_discount' => 240000,
                    // Synthetic amounts are not a signed customer quote.
                    'is_pricing_final' => false,
                    'terms_accepted' => true,
                    'work_started_at' => null,
                    'work_finished_at' => null,
                    'customer_confirmed_at' => null,
                    'cancelled_at' => null,
                    ]);
                    CleaningBooking::withoutEvents(static fn () => $booking->save());
                }
                // Repair earlier generated QA-only fixtures as well.
                if ($booking->address_latitude === null || $booking->address_longitude === null) {
                    $booking->forceFill(['address_latitude' => 36.2168, 'address_longitude' => 37.1317])->saveQuietly();
                }
                if (! data_get($booking->property_details, '_booking_v2_approved_total') && $booking->is_pricing_final) {
                    $booking->forceFill(['is_pricing_final' => false])->saveQuietly();
                }

                $firstRoom = $booking->rooms()->first();
                if ($firstRoom === null) {
                    foreach ($source->rooms as $room) {
                        $copy = $room->replicate();
                        $copy->cleaning_booking_id = $booking->id;
                        $copy->saveQuietly();
                        $firstRoom ??= $copy;
                    }
                }
                if ($firstRoom === null) {
                    throw new RuntimeException('The source cleaning QA booking has no room to schedule.');
                }

                CleaningRoomSchedule::query()->firstOrCreate([
                    'cleaning_booking_id' => $booking->id,
                    'schedule_key' => 'qa.independent.order',
                ], [
                    'cleaning_booking_room_id' => $firstRoom->id,
                    'timezone' => 'Asia/Damascus',
                    'starts_at' => $appointment->utc(),
                    'ends_at' => $appointment->addHours(2)->utc(),
                    'required_workers' => 2,
                    'work_minutes' => 240,
                    'duration_minutes' => 120,
                ]);

                if (! DB::table('cleaning_supervisor_assignments')
                    ->where('cleaning_booking_id', $booking->id)
                    ->whereNull('released_at')
                    ->exists()) {
                    DB::table('cleaning_supervisor_assignments')->insert([
                        'cleaning_booking_id' => $booking->id,
                        'supervisor_user_id' => $supervisor->id,
                        'assigned_by_user_id' => $supervisor->id,
                        'assigned_at' => now(),
                        'active_slot' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }, 3);
    }
}
