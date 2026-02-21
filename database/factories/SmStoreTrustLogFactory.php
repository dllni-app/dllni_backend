<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmStoreTrustLog;

final class SmStoreTrustLogFactory extends Factory
{
    protected $model = SmStoreTrustLog::class;

    public function definition(): array
    {
        $scoreAfter = fake()->numberBetween(0, 100);

        return [
            'store_id' => SmStoreFactory::new(),
            'event_type' => fake()->word(),
            'score_delta' => fake()->numberBetween(-10, 10),
            'score_after' => $scoreAfter,
            'reference_type' => null,
            'reference_id' => null,
            'notes' => fake()->optional()->sentence(),
            'triggered_by_user_id' => User::factory(),
        ];
    }
}
