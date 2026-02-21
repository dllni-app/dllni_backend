<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmOrderStatusLog;

final class SmOrderStatusLogFactory extends Factory
{
    protected $model = SmOrderStatusLog::class;

    public function definition(): array
    {
        return [
            'order_id' => SmOrderFactory::new(),
            'from_status' => fake()->randomElement(['pending', 'accepted', 'preparing']),
            'to_status' => fake()->randomElement(['accepted', 'preparing', 'ready_for_pickup']),
            'notes' => fake()->optional()->sentence(),
            'changed_by_user_id' => User::factory(),
        ];
    }
}
