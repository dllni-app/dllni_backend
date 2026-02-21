<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmAssistantQuery;

final class SmAssistantQueryFactory extends Factory
{
    protected $model = SmAssistantQuery::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_id' => SmStoreFactory::new(),
            'input_mode' => fake()->randomElement(['text', 'voice']),
            'query_text' => fake()->optional()->sentence(),
            'voice_file_path' => null,
            'matched_product_ids' => [fake()->numberBetween(1, 50)],
            'matched_recipe_id' => null,
            'response_payload' => ['intent' => fake()->word()],
        ];
    }
}
