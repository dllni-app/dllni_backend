<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

final class CleaningSupervisorSeeder extends Seeder
{
    public const PHONE = '+963944100006';

    public const EMAIL = 'supervisor@dllni.sy';

    public function run(): void
    {
        // Never create a publicly known demo login in production.
        if (config('app.env') === 'production') {
            return;
        }

        $supervisor = User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'مشرف فريق التنظيف التجريبي',
                'phone' => self::PHONE,
                'password' => 'password',
                'is_active' => true,
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        $role = Role::findOrCreate('Cleaning Supervisor', config('auth.defaults.guard', 'web'));
        if (! $supervisor->hasRole($role)) {
            $supervisor->assignRole($role);
        }
    }
}
