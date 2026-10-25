<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;

final class CleaningWorkerExtensionScenarioSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }
        $billingPolicy = CleaningBillingPolicy::where('is_default', true)->first();

        if (! $billingPolicy) {
            return;
        }

        $customer = User::updateOrCreate(
            ['email' => 'cleaning.extension.customer@dllni.sy'],
            [
                'name' => 'يزن الخطيب',
                'phone' => '+963944300001',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ]
        );

        $policy = CancellationPolicy::where('module', 'cleaning')->where('is_default', true)->first();
        $today = now()->startOfDay();

        foreach ($this->workerRows() as $index => $row) {
            $worker = Worker::whereHas('user', fn ($query) => $query->where('email', $row['email']))->first();

            if (! $worker) {
                continue;
            }

            $this->seedRows($worker, $customer, $billingPolicy, $policy, $row, $today->copy()->addDays($index));
        }
    }

    private function workerRows(): array
    {
        return [
            ['email' => 'cleaning.worker@dllni.sy', 'prefix' => 'CLN-AR-W1', 'minutes' => 30, 'property_type' => 'apartment'],
            ['email' => 'cleaning.worker2@dllni.sy', 'prefix' => 'CLN-AR-W2', 'minutes' => 45, 'property_type' => 'apartment'],
            ['email' => 'cleaning.worker3@dllni.sy', 'prefix' => 'CLN-AR-W3', 'minutes' => 60, 'property_type' => 'office'],
        ];
    }

    private function seedRows(Worker $worker, User $customer, CleaningBillingPolicy $billingPolicy, ?CancellationPolicy $policy, array $row, CarbonInterface $date): void
    {
        $cases = [
            ['suffix' => 'EXT', 'status' => CleaningBookingStatus::TimeExtensionRequested, 'day' => 0, 'time' => '10:00', 'price' => 100, 'pending_warning' => true],
            ['suffix' => 'INPROG', 'status' => CleaningBookingStatus::InProgress, 'day' => 1, 'time' => '12:00', 'price' => 100],
            ['suffix' => 'ASSIGNED', 'status' => CleaningBookingStatus::WorkerAssigned, 'day' => 2, 'time' => '14:00', 'price' => 200],
            ['suffix' => 'COMPLETED', 'status' => CleaningBookingStatus::Completed, 'day' => -1, 'time' => '09:00', 'price' => 200],
            ['suffix' => 'PENDING', 'status' => CleaningBookingStatus::Pending, 'day' => 3, 'time' => '16:00', 'price' => 50, 'assigned' => false],
        ];

        foreach ($cases as $case) {
            $booking = $this->booking($worker, $customer, $billingPolicy, $policy, $row, $case, $date->copy()->addDays((int) $case['day']));

            if (($case['pending_warning'] ?? false) === true) {
                $this->warning($booking, (int) $row['minutes']);
            }
        }
    }

    private function booking(Worker $worker, User $customer, CleaningBillingPolicy $billingPolicy, ?CancellationPolicy $policy, array $row, array $case, CarbonInterface $date): CleaningBooking
    {
        $status = $case['status'];
        $assigned = $case['assigned'] ?? true;
        $basePrice = (float) $case['price'];
        $travelFee = 10.00;
        $isOffice = $row['property_type'] === 'office';

        $booking = CleaningBooking::updateOrCreate(
            ['booking_number' => $row['prefix'].'-'.$case['suffix'].'-0001'],
            [
                'customer_id' => $customer->id,
                'worker_id' => $assigned ? $worker->id : null,
                'preferred_worker_id' => $worker->id,
                'assignment_mode' => CleaningAssignmentMode::PreferredWorker->value,
                'number_of_workers' => 1,
                'cancellation_policy_id' => $policy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'status' => $status,
                'property_type' => $row['property_type'],
                'neighborhood_name' => 'الجميلية',
                'property_details' => [
                    'location_name' => $isOffice ? 'مكتب تجريبي في الجميلية' : 'شقة تجريبية في العزيزية',
                    'address' => 'حلب، الجميلية، قرب ساحة سعد الله الجابري',
                    'bedrooms' => $isOffice ? 0 : 2,
                    'rooms' => $isOffice ? 4 : 3,
                    'bathrooms' => $isOffice ? 2 : 1,
                    'kitchens' => 1,
                    'living_room_size' => 'medium',
                    'cleaning_mode' => 'deep',
                    'room_size_breakdown' => [
                        'bedroom' => ['medium' => $isOffice ? 0 : 2],
                        'bathroom' => ['small' => $isOffice ? 2 : 1],
                        'kitchen' => ['medium' => 1],
                        'living_room' => ['medium' => 1],
                    ],
                ],
                'cleaning_services' => ['تنظيف عميق'],
                'estimated_sqm' => $isOffice ? 120 : 90,
                'estimated_hours' => $isOffice ? 4 : 3,
                'scheduled_date' => $date,
                'scheduled_time' => $case['time'],
                'total_hours' => $isOffice ? 4 : 3,
                'base_price' => $basePrice,
                'addons_total' => 0,
                'travel_fee' => $travelFee,
                'cancellation_fee' => 0,
                'admin_margin_amount' => round($basePrice * 0.10, 2),
                'total_price' => round($basePrice * 1.10 + $travelFee, 2),
                'terms_accepted' => true,
                'address_latitude' => $isOffice ? 36.2168 : 36.1795,
                'address_longitude' => $isOffice ? 37.1317 : 37.1082,
                'work_started_at' => $this->workStartedAt($status, $date),
                'work_finished_at' => $this->workFinishedAt($status, $date),
                'started_travel_at' => $this->startedTravelAt($status, $date),
                'arrived_at' => $this->arrivedAt($status, $date),
                'customer_confirmed_at' => $this->customerConfirmedAt($status, $date),
            ]
        );

        // Legacy booking-updated listeners can reprice seed fixtures during
        // Eloquent saves. Pin the synthetic financial snapshot explicitly so
        // the customer total and worker commission contract stay consistent.
        DB::table('cleaning_bookings')->where('id', $booking->id)->update([
            'travel_fee' => $travelFee,
            'admin_margin_amount' => round($basePrice * 0.10, 2),
            'total_price' => round($basePrice * 1.10 + $travelFee, 2),
        ]);

        return $booking->refresh();
    }

    private function warning(CleaningBooking $booking, int $minutes): void
    {
        $quote = app(CleaningExtendedTimePricingService::class)
            ->quoteForBooking($booking, $minutes);
        CleaningTimeWarning::updateOrCreate(
            ['booking_id' => $booking->id, 'booking_type' => 'cleaning_booking'],
            [
                'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
                'customer_message' => 'أرغب في تمديد مدة الخدمة لإكمال بقية العمل.',
                'worker_response' => null,
                'sent_at' => now()->subMinutes(30),
                'customer_responded_at' => now()->subMinutes(25),
                'worker_responded_at' => null,
                'additional_minutes' => $minutes,
                'quoted_base_amount' => $quote['baseAmount'],
                'quoted_admin_margin_amount' => $quote['adminMargin'],
                'quoted_amount' => $quote['totalAmount'],
                'quoted_currency' => $quote['currency'],
                'price_applied_at' => null,
                'worker_reject_message' => null,
            ]
        );
    }

    private function workStartedAt(CleaningBookingStatus $status, CarbonInterface $date): ?CarbonInterface
    {
        return in_array($status, [CleaningBookingStatus::InProgress, CleaningBookingStatus::TimeExtensionRequested, CleaningBookingStatus::Completed], true) ? $date->copy()->setTime(10, 0) : null;
    }

    private function workFinishedAt(CleaningBookingStatus $status, CarbonInterface $date): ?CarbonInterface
    {
        return match ($status) {
            CleaningBookingStatus::TimeExtensionRequested => $date->copy()->setTime(13, 0),
            CleaningBookingStatus::Completed => $date->copy()->setTime(13, 30),
            default => null,
        };
    }

    private function startedTravelAt(CleaningBookingStatus $status, CarbonInterface $date): ?CarbonInterface
    {
        return in_array($status, [CleaningBookingStatus::WorkerAssigned, CleaningBookingStatus::InProgress, CleaningBookingStatus::TimeExtensionRequested, CleaningBookingStatus::Completed], true) ? $date->copy()->setTime(9, 30) : null;
    }

    private function arrivedAt(CleaningBookingStatus $status, CarbonInterface $date): ?CarbonInterface
    {
        return in_array($status, [CleaningBookingStatus::InProgress, CleaningBookingStatus::TimeExtensionRequested, CleaningBookingStatus::Completed], true) ? $date->copy()->setTime(9, 50) : null;
    }

    private function customerConfirmedAt(CleaningBookingStatus $status, CarbonInterface $date): ?CarbonInterface
    {
        return match ($status) {
            CleaningBookingStatus::InProgress, CleaningBookingStatus::TimeExtensionRequested => $date->copy()->setTime(10, 0),
            CleaningBookingStatus::Completed => $date->copy()->setTime(13, 35),
            default => null,
        };
    }
}
