<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::updateOrCreate(
            ['email' => 'cleaning.customer@dllni.sy'],
            [
                'name' => 'مروان الحسين',
                'phone' => '+963944120190',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $worker = Worker::first();
        $cancellationPolicy = CancellationPolicy::where('module', 'cleaning')->where('is_default', true)->first();
        $billingPolicy = CleaningBillingPolicy::where('is_default', true)->first();

        if (! $worker || ! $billingPolicy) {
            return;
        }

        $statuses = [
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::AwaitingCustomerCompletion->value,
            CleaningBookingStatus::TimeExtensionRequested->value,
            CleaningBookingStatus::PartiallyCompleted->value,
            CleaningBookingStatus::InProgress->value,
            CleaningBookingStatus::WorkerAssigned->value,
            CleaningBookingStatus::Pending->value,
            CleaningBookingStatus::AwaitingStartVerification->value,
            CleaningBookingStatus::Cancelled->value,
        ];

        $basePrices = [200.00, 200.00, 500.00, 200.00, 100.00, 200.00, 100.00, 200.00, 100.00];
        $travelFees = [25.00, 25.00, 50.00, 25.00, 25.00, 25.00, 10.00, 25.00, 10.00];

        foreach ($statuses as $i => $status) {
            $scheduledDate = now()->startOfDay()->addDays($i);
            $basePrice = $basePrices[$i];
            $travelFee = $travelFees[$i];
            $totalPrice = $basePrice + $travelFee;

            CleaningBooking::updateOrCreate(['booking_number' => 'CLN-QA-'.mb_str_pad((string) ($i + 1001), 4, '0', STR_PAD_LEFT)], [
                'customer_id' => $customer->id,
                'worker_id' => $worker->id,
                'number_of_workers' => $status === CleaningBookingStatus::PartiallyCompleted->value ? 2 : 1,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'status' => $status,
                'property_type' => 'apartment',
                'property_details' => [
                    'location_name' => 'شقة سكنية في الجميلية',
                    'address' => 'حلب، الجميلية، قرب ساحة سعد الله الجابري',
                    'bedrooms' => 2,
                    'bathrooms' => 1,
                    'kitchens' => 1,
                    'living_room_size' => 'medium',
                ],
                'cleaning_services' => ['تنظيف الشقة المعياري'],
                'estimated_sqm' => 85,
                'estimated_hours' => 3.5,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => '10:00',
                'total_hours' => 3.5,
                'base_price' => $basePrice,
                'addons_total' => 0,
                'travel_fee' => $travelFee,
                'cancellation_fee' => 0,
                'total_price' => $totalPrice,
                'terms_accepted' => true,
                'work_started_at' => in_array($status, [
                    CleaningBookingStatus::Completed->value,
                    CleaningBookingStatus::InProgress->value,
                    CleaningBookingStatus::AwaitingCustomerCompletion->value,
                    CleaningBookingStatus::TimeExtensionRequested->value,
                    CleaningBookingStatus::PartiallyCompleted->value,
                ], true) ? $scheduledDate->copy()->setTime(10, 0) : null,
                'work_finished_at' => $status === CleaningBookingStatus::Completed->value ? $scheduledDate->copy()->setTime(13, 30) : null,
                'customer_confirmed_at' => $status === CleaningBookingStatus::Completed->value ? $scheduledDate->copy()->setTime(13, 35) : null,
            ]);
        }
    }
}
