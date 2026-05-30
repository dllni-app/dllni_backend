<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Delivery\Models\DeliveryCompany;

/**
 * @extends Factory<DeliveryCompany>
 */
final class DeliveryCompanyFactory extends Factory
{
    protected $model = DeliveryCompany::class;

    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(33.4, 33.6),
            'longitude' => fake()->longitude(36.2, 36.4),
            'is_active' => true,
            'is_suspended' => false,
            'suspension_reason' => null,
            'suspended_until' => null,
            'financial_limit' => 100000,
        ];
    }
}
