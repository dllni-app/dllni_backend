<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

final class VerifiedUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()
            ->where('phone', '+963944000222')
            ->orWhere('email', 'user@dllni.sy')
            ->first() ?? new User();

        $user->forceFill([
            'name' => 'مستخدم التطبيق',
            'phone' => '+963944000222',
            'email' => 'user@dllni.sy',
            'password' => bcrypt('password'),
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'is_active' => true,
        ])->save();
    }
}
