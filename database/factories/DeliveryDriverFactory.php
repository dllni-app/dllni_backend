<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;

/**
 * @extends Factory<DeliveryDriver>
 */
final class DeliveryDriverFactory extends Factory
{
    protected $model = DeliveryDriver::class;

    public function definition(): array
    {
        return [
            'company_id' => DeliveryCompany::factory(),
            'user_id' => User::factory()->state([
                'module_type' => UserModuleType::DeliveryDriver->value,
            ]),
            'first_name' => fake()->firstName(),
            'phone' => fake()->phoneNumber(),
            'vehicle_type' => 'motorcycle',
            'plate_number' => fake()->bothify('??-####'),
            'availability_status' => 'available',
            'is_active' => true,
            'is_suspended' => false,
            'suspended_until' => null,
            'suspension_reason' => null,
            'trust_score' => 100,
            'open_disputes_count' => 0,
            'last_seen_at' => now(),
        ];
    }

    public function available(): self
    {
        return $this->state(fn (): array => [
            'availability_status' => 'available',
            'last_seen_at' => now(),
        ]);
    }

    public function busy(): self
    {
        return $this->state(fn (): array => [
            'availability_status' => 'busy',
        ]);
    }
}
