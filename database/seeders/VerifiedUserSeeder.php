<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

final class VerifiedUserSeeder extends Seeder
{
    public function run(): void
    {
        Model::unguard();

        User::updateOrCreate(
            ['phone' => '+963944000222'],
            [
                'name' => 'User',
                'email' => 'user@example.com',
                'password' => bcrypt('secret123'),
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
            ],
        );
    }
}
