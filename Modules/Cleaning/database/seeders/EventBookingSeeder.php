<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Enums\EventType;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\EventBooking;

final class EventBookingSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::firstOrCreate(
            ['email' => 'event.customer@dllni.sy'],
            [
                'name' => 'سارة عثمان',
                'phone' => '+963944120191',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
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

        $basePrices = [100.00, 200.00, 500.00];
        $travelFees = [10.00, 25.00, 50.00];

        foreach ($eventTypes as $i => $eventType) {
            $scheduledDate = now()->startOfDay()->addDays($i + 7);
            $basePrice = $basePrices[$i];
            $travelFee = $travelFees[$i];
            $totalPrice = $basePrice + $travelFee;

            EventBooking::updateOrCreate(['booking_number' => 'EVT-QA-'.mb_str_pad((string) ($i + 2001), 4, '0', STR_PAD_LEFT)], [
                'customer_id' => $customer->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'billing_policy_id' => $billingPolicy->id,
                'booking_number' => 'EVT-QA-'.mb_str_pad((string) ($i + 2001), 4, '0', STR_PAD_LEFT),
                'status' => $statuses[$i],
                'event_type' => $eventType,
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
