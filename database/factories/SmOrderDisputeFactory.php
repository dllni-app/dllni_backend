<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmOrderDispute;

final class SmOrderDisputeFactory extends Factory
{
    protected $model = SmOrderDispute::class;

    public function definition(): array
    {
        return [
            'order_id' => SmOrderFactory::new(),
            'opened_by_user_id' => User::factory(),
            'ticket_number' => mb_strtoupper(fake()->bothify('DSP-####')),
            'status' => 'open',
            'reason' => fake()->optional()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'resolved_at' => null,
            'resolved_by_user_id' => null,
            'resolution_notes' => null,
        ];
    }
}
