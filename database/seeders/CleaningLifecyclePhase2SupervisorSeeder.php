<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Extra isolated supervisor identity for simultaneous Phase 2 group bookings.
 * Existing QA supervisor #7 has a real schedule conflict at booking #84's time.
 * Never weaken overlap protection or modify older seeded visits to make room.
 */
final class CleaningLifecyclePhase2SupervisorSeeder extends Seeder
{
    public const EMAIL = 'phase2.supervisor@dllni.sy';

    public const PHONE = '+963944100206';

    public function run(): void
    {
        if (app()->environment('production')
            || config('database.default') !== 'mysql'
            || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
            || \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'alnadha_worker_test') {
            throw new \RuntimeException('This fixture is restricted to the existing local QA database.');
        }

        $user = User::query()->firstOrCreate(['email' => self::EMAIL], [
            'name' => 'مشرف اختبارات المرحلة الثانية',
            'phone' => self::PHONE,
            'password' => 'password',
            'is_active' => true,
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);

        $role = Role::findOrCreate('Cleaning Supervisor', config('auth.defaults.guard', 'web'));
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
