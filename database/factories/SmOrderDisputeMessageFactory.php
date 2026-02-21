<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmOrderDisputeMessage;

final class SmOrderDisputeMessageFactory extends Factory
{
    protected $model = SmOrderDisputeMessage::class;

    public function definition(): array
    {
        return [
            'dispute_id' => SmOrderDisputeFactory::new(),
            'user_id' => User::factory(),
            'message' => fake()->sentence(),
            'is_internal' => false,
        ];
    }
}
