<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryOrder;

/**
 * @extends Factory<DeliveryOrder>
 */
final class DeliveryOrderFactory extends Factory
{
    protected $model = DeliveryOrder::class;

    public function definition(): array
    {
        return [
            'company_id' => DeliveryCompany::factory(),
            'driver_id' => null,
            'order_number' => 'DEL-'.mb_strtoupper(Str::random(8)).'-'.random_int(1000, 9999),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->phoneNumber(),
            'customer_notes' => null,
            'pickup_address' => fake()->streetAddress(),
            'pickup_latitude' => fake()->latitude(33.4, 33.6),
            'pickup_longitude' => fake()->longitude(36.2, 36.4),
            'dropoff_address' => fake()->streetAddress(),
            'dropoff_latitude' => fake()->latitude(33.4, 33.6),
            'dropoff_longitude' => fake()->longitude(36.2, 36.4),
            'distance_km' => fake()->randomFloat(2, 1, 15),
            'delivery_fee' => fake()->randomFloat(2, 5000, 25000),
            'currency' => 'SYP',
            'status' => DeliveryOrderStatus::New->value,
        ];
    }
}
