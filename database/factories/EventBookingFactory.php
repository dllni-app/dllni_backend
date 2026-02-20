<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Enums\EventType;
use Modules\Cleaning\Models\EventBooking;

/**
 * @extends Factory<EventBooking>
 */
final class EventBookingFactory extends Factory
{
    protected $model = EventBooking::class;

    public function definition(): array
    {
        $scheduledDate = fake()->dateTimeBetween('now', '+30 days');
        $basePrice = fake()->randomFloat(2, 100, 300);
        $travelFee = fake()->randomFloat(2, 10, 30);
        $totalPrice = $basePrice + $travelFee;

        return [
            'customer_id' => User::factory(),
            'cancellation_policy_id' => null,
            'billing_policy_id' => null,
            'booking_number' => 'EVT-'.mb_strtoupper(Str::random(6)).'-'.fake()->unique()->randomNumber(4),
            'status' => fake()->randomElement(EventBookingStatus::class)->value,
            'event_type' => fake()->randomElement(EventType::class)->value,
            'guest_count_min' => fake()->numberBetween(5, 20),
            'guest_count_max' => fake()->numberBetween(20, 100),
            'gender_preference' => null,
            'suggested_team_size' => fake()->numberBetween(2, 6),
            'scheduled_date' => $scheduledDate,
            'scheduled_time' => fake()->time('H:i'),
            'total_hours' => fake()->randomFloat(1, 4, 12),
            'base_price' => $basePrice,
            'travel_fee' => $travelFee,
            'total_price' => $totalPrice,
            'terms_accepted' => true,
            'cancelled_at' => null,
        ];
    }
}
