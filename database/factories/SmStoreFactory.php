<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Supermarket\Models\SmStore;

/**
 * @extends Factory<SmStore>
 */
final class SmStoreFactory extends Factory
{
    protected $model = SmStore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = fake()->unique()->company();

        return [
            'owner_user_id' => User::factory(),
            'name' => $company,
            'slug' => Str::slug($company . '-' . fake()->unique()->word()),
            'description' => fake()->optional()->sentence(),
            'address' => fake()->optional()->address(),
            'latitude' => fake()->optional()->latitude(),
            'longitude' => fake()->optional()->longitude(),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'average_rating' => 0,
            'total_reviews' => 0,
            'trust_score' => 0,
            'warning_count' => 0,
            'is_active' => true,
            'is_featured' => false,
            'suspension_until' => null,
        ];
    }
}
