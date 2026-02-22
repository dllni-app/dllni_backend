<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 20, 100);
        $totalAmount = $subtotal + fake()->randomFloat(2, 2, 10);

        return [
            'user_id' => User::factory(),
            'restaurant_id' => Restaurant::factory(),
            'promo_code_id' => null,
            'assigned_staff_id' => null,
            'cancellation_policy_id' => null,
            'order_number' => 'ORD-'.mb_strtoupper(Str::random(8)).'-'.fake()->unique()->randomNumber(4),
            'status' => fake()->randomElement(OrderStatus::class)->value,
            'order_type' => fake()->randomElement(OrderType::class)->value,
            'pickup_mode' => fake()->randomElement(RestaurantPickupMode::class)->value,
            'pickup_scheduled_for' => null,
            'ready_for_pickup_at' => null,
            'picked_up_at' => null,
            'customer_pickup_confirmed_at' => null,
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'tax_amount' => fake()->randomFloat(2, 1, 5),
            'service_fee' => fake()->randomFloat(2, 0, 3),
            'total_amount' => $totalAmount,
            'cancellation_fee_amount' => null,
            'cancellation_policy_snapshot' => null,
            'special_instructions' => null,
            'accepted_at' => null,
            'preparing_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }
}
