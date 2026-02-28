<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admins = [
            ['name' => 'Admin', 'email' => 'admin@admin.com'],
            ['name' => 'Admin User', 'email' => 'admin@example.com'],
        ];

        foreach ($admins as $adminData) {
            $admin = User::updateOrCreate(
                ['email' => $adminData['email']],
                [
                    'name' => $adminData['name'],
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );

            if (! $admin->hasRole('admin')) {
                $admin->assignRole('admin');
            }
        }
    }
}
