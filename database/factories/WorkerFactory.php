<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Worker>
 */
final class WorkerFactory extends Factory
{
    protected $model = Worker::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'bio' => fake()->optional()->paragraph(),
            'average_rating' => fake()->randomFloat(2, 3, 5),
            'total_completed_jobs' => fake()->numberBetween(0, 200),
            'trust_score' => fake()->numberBetween(0, 100),
            'acceptance_rate' => fake()->randomFloat(2, 0.7, 1),
            'cancellation_rate' => fake()->randomFloat(2, 0, 0.2),
            'open_disputes_count' => fake()->numberBetween(0, 5),
            'is_active' => true,
            'is_suspended' => false,
            'suspended_until' => null,
            'home_address' => fake()->optional()->address(),
            'home_latitude' => fake()->optional()->latitude(),
            'home_longitude' => fake()->optional()->longitude(),
            'default_working_hours' => null,
        ];
    }
}
