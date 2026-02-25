<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningService;

final class CleaningBookingSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::firstOrCreate(
            ['email' => 'cleaning.customer@example.com'],
            [
                'name' => 'عميل التنظيف',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $worker = Worker::first();
        $cancellationPolicy = CancellationPolicy::where('module', 'cleaning')->where('is_default', true)->first();
        $billingPolicy = CleaningBillingPolicy::where('is_default', true)->first();
        $standardService = CleaningService::where('slug', 'standard-apartment-cleaning')->first();

        if (! $worker || ! $billingPolicy || ! $standardService) {
            return;
        }

        $statuses = [
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::InProgress->value,
            CleaningBookingStatus::WorkerAssigned->value,
            CleaningBookingStatus::Pending->value,
        ];

        for ($i = 0; $i < 5; $i++) {
            $scheduledDate = now()->addDays($i);
            $basePrice = fake()->randomFloat(2, 55, 90);
            $travelFee = fake()->randomFloat(2, 5, 15);
            $totalPrice = $basePrice + $travelFee;

            $bookingNumber = 'CLN-'.mb_strtoupper(Str::random(6)).'-'.$i;
            if (CleaningBooking::where('booking_number', $bookingNumber)->exists()) {
                continue;
            }

            CleaningBooking::create([
                'customer_id' => $customer->id,
                'worker_id' => $worker->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'booking_number' => $bookingNumber,
                'status' => $statuses[$i],
                'property_type' => 'apartment',
                'property_details' => [
                    'bedrooms' => 2,
                    'bathrooms' => 1,
                    'living_room_size' => 'medium',
                ],
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
                'work_started_at' => in_array($statuses[$i], ['completed', 'in_progress']) ? $scheduledDate->copy()->setTime(10, 0) : null,
                'work_finished_at' => $statuses[$i] === 'completed' ? $scheduledDate->copy()->setTime(13, 30) : null,
                'customer_confirmed_at' => in_array($statuses[$i], ['completed', 'in_progress']) ? $scheduledDate->copy()->setTime(13, 35) : null,
            ]);
        }
    }
}
