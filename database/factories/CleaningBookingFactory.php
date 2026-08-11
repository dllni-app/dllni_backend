<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenderPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

/**
 * @extends Factory<CleaningBooking>
 */
final class CleaningBookingFactory extends Factory
{
    protected $model = CleaningBooking::class;

    public function definition(): array
    {
        $scheduledDate = fake()->dateTimeBetween('now', '+30 days');
        $basePrice = fake()->randomFloat(2, 50, 150);
        $travelFee = fake()->randomFloat(2, 5, 20);
        $totalPrice = $basePrice + $travelFee;

        return [
            'customer_id' => User::factory(),
            'worker_id' => null,
            'preferred_worker_id' => null,
            'number_of_workers' => 1,
            'gender_preference' => fake()->randomElement(GenderPreference::class)->value,
            'cancellation_policy_id' => null,
            'billing_policy_id' => null,
            'booking_number' => 'CLN-'.mb_strtoupper(Str::random(6)).'-'.fake()->unique()->randomNumber(4),
            'status' => fake()->randomElement(CleaningBookingStatus::class)->value,
            'property_type' => fake()->randomElement(['apartment', 'house', 'villa']),
            'property_details' => [
                'bedrooms' => fake()->numberBetween(1, 5),
                'bathrooms' => fake()->numberBetween(1, 3),
                'kitchens' => fake()->numberBetween(1, 2),
                'living_room_size' => fake()->randomElement(['small', 'medium', 'large']),
            ],
            'neighborhood_id' => null,
            'neighborhood_name' => null,
            'estimated_sqm' => fake()->randomFloat(2, 50, 300),
            'estimated_hours' => fake()->randomFloat(1, 2, 8),
            'scheduled_date' => $scheduledDate,
            'scheduled_time' => fake()->time('H:i'),
            'total_hours' => fake()->randomFloat(1, 2, 8),
            'base_price' => $basePrice,
            'addons_total' => 0,
            'travel_fee' => $travelFee,
            'travel_distance_km' => null,
            'admin_margin_amount' => 0,
            'is_pricing_final' => true,
            'cancellation_fee' => 0,
            'total_price' => $totalPrice,
            'terms_accepted' => true,
            'work_started_at' => null,
            'work_finished_at' => null,
            'customer_confirmed_at' => null,
            'cancelled_at' => null,
        ];
    }
}
