<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Enums\EventType;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\EventBooking;

final class EventBookingSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::firstOrCreate(
            ['email' => 'event.customer@example.com'],
            [
                'name' => 'Event Customer',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $cancellationPolicy = CancellationPolicy::where('module', 'cleaning')->where('is_default', true)->first();
        $billingPolicy = CleaningBillingPolicy::where('is_default', true)->first();

        if (! $billingPolicy) {
            return;
        }

        $eventTypes = [
            EventType::FamilyDinner->value,
            EventType::Birthday->value,
            EventType::LargeGathering->value,
        ];

        $statuses = [
            EventBookingStatus::Completed->value,
            EventBookingStatus::Confirmed->value,
            EventBookingStatus::Pending->value,
        ];

        for ($i = 0; $i < 3; $i++) {
            $scheduledDate = now()->addDays($i + 7);
            $basePrice = fake()->randomFloat(2, 100, 250);
            $travelFee = fake()->randomFloat(2, 10, 25);
            $totalPrice = $basePrice + $travelFee;

            $bookingNumber = 'EVT-'.mb_strtoupper(Str::random(6)).'-'.$i;
            if (EventBooking::where('booking_number', $bookingNumber)->exists()) {
                continue;
            }

            EventBooking::create([
                'customer_id' => $customer->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'booking_number' => $bookingNumber,
                'status' => $statuses[$i],
                'event_type' => $eventTypes[$i],
                'guest_count_min' => $i === 0 ? 10 : 20,
                'guest_count_max' => $i === 0 ? 25 : 50,
                'gender_preference' => 'any',
                'suggested_team_size' => $i + 2,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => '18:00',
                'total_hours' => 6,
                'base_price' => $basePrice,
                'travel_fee' => $travelFee,
                'total_price' => $totalPrice,
                'terms_accepted' => true,
            ]);
        }
    }
}
