<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserModuleType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmStoreStaff;

final class SmStoreStaffFactory extends Factory
{
    protected $model = SmStoreStaff::class;

    public function definition(): array
    {
        return [
            'store_id' => SmStoreFactory::new(),
            'user_id' => UserFactory::new()->state(fn (): array => [
                'module_type' => UserModuleType::SupermarketSeller->value,
                'phone' => fake()->optional()->e164PhoneNumber(),
            ]),
            'is_active' => fake()->boolean(85),
        ];
    }
}
