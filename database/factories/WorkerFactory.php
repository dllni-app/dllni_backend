<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkerPreferredWorkType;
use App\Models\CleaningWorkerDeposit;
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
            'birthday' => fake()->optional()->date(),
            'preferred_work_type' => WorkerPreferredWorkType::Both,
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

    /**
     * Give a worker an explicit cleaning allowance for scenarios that exercise
     * dispatch or booking acceptance rather than financial eligibility itself.
     */
    public function financiallyEligible(
        float $balance = 100000,
        float $maxNegativeBalance = 100000,
    ): static {
        return $this->afterCreating(function (Worker $worker) use ($balance, $maxNegativeBalance): void {
            CleaningWorkerDeposit::query()->updateOrCreate(
                ['worker_id' => $worker->id],
                [
                    'current_balance' => $balance,
                    'deposited_total' => max($balance, 0),
                    'withdrawn_total' => 0,
                    'minimum_required' => 0,
                    'max_negative_balance' => $maxNegativeBalance,
                ],
            );
        });
    }
}
