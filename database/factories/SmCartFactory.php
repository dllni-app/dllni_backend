<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Supermarket\Models\SmCart;

final class SmCartFactory extends Factory
{
    protected $model = SmCart::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }
}
