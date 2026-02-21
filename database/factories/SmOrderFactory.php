<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderFactory extends Factory
{
    protected $model = SmOrder::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 200);
        $serviceFee = fake()->randomFloat(2, 0, 20);
        $discount = fake()->randomFloat(2, 0, 20);

        return [
            'customer_id' => User::factory(),
            'store_id' => SmStoreFactory::new(),
            'coupon_id' => null,
            'cancellation_policy_id' => null,
            'order_number' => mb_strtoupper(fake()->bothify('ORD-####')),
            'status' => fake()->randomElement(['pending', 'accepted', 'preparing', 'ready_for_pickup', 'completed']),
            'pickup_mode' => fake()->randomElement(['immediate_pickup', 'scheduled_pickup']),
            'pickup_scheduled_for' => null,
            'ready_for_pickup_at' => null,
            'picked_up_at' => null,
            'customer_pickup_confirmed_at' => null,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'service_fee' => $serviceFee,
            'total_amount' => max($subtotal + $serviceFee - $discount, 0),
            'cancellation_fee_amount' => 0,
            'cancellation_policy_snapshot' => null,
            'special_instructions' => fake()->optional()->sentence(),
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }
}
