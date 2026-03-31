<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\User\Models\UserAddress;

/**
 * @extends Factory<UserAddress>
 */
final class UserAddressFactory extends Factory
{
    protected $model = UserAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['المنزل', 'العمل', 'أخرى']),
            'city' => fake()->city(),
            'neighborhood' => fake()->streetName(),
            'street' => fake()->streetName(),
            'building' => (string) fake()->buildingNumber(),
            'floor' => (string) fake()->numberBetween(1, 10),
            'directions' => fake()->optional()->sentence(),
            'latitude' => null,
            'longitude' => null,
            'is_default' => false,
        ];
    }
}
