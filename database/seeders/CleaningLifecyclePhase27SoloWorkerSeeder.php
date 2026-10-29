<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserModuleType;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkerZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A separate, non-conflicting QA worker for Phase 2.7 solo lifecycle checks.
 * Only the authorized local test database may run this targeted Seeder.
 */
final class CleaningLifecyclePhase27SoloWorkerSeeder extends Seeder
{
    public const EMAIL = 'phase27.solo.worker@dllni.sy';

    public function run(): void
    {
        if (app()->environment('production') || config('database.default') !== 'mysql'
            || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
            || DB::connection()->getDatabaseName() !== 'alnadha_worker_test') {
            throw new \RuntimeException('QA Seeder cannot run against this database.');
        }

        $source = Worker::query()->with('user', 'zones')->findOrFail(1);
        $user = User::query()->firstOrCreate(['email' => self::EMAIL], [
            'name' => 'عامل اختبار الزيارة الفردية',
            'phone' => '+963944100207',
            'module_type' => UserModuleType::CleaningWorker,
            'password' => 'password',
            'is_active' => true,
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);

        $worker = Worker::query()->firstOrCreate(['user_id' => $user->id], [
            'first_name' => 'عامل اختبار فردي',
            'gender' => 'male',
            'bio' => 'حساب اختبارات محلية مستقل لتنفيذ رحلة الطلب الفردي.',
            'preferred_work_type' => 'both',
            'is_active' => true,
            'is_suspended' => false,
            'is_verified' => true,
            'security_deposit_status' => 'active',
            'home_address' => $source->home_address,
            'home_latitude' => $source->home_latitude,
            'home_longitude' => $source->home_longitude,
            'default_working_hours' => $source->default_working_hours,
        ]);

        CleaningWorkerDeposit::query()->firstOrCreate(['worker_id' => $worker->id], [
            'current_balance' => 1000000,
            'deposited_total' => 1000000,
            'withdrawn_total' => 0,
            'is_active' => true,
        ]);
        foreach ($source->zones as $zone) {
            WorkerZone::query()->firstOrCreate([
                'worker_id' => $worker->id,
                'name' => 'QA Solo – '.$zone->name,
            ], [
                'polygon' => $zone->polygon,
                'neighborhood_id' => $zone->neighborhood_id,
                'is_active' => true,
            ]);
        }
        $session = \Modules\Cleaning\Models\CleaningBookingSession::findOrFail(48);
        WorkerAvailability::query()->firstOrCreate([
            'worker_id' => $worker->id,
            'availability_date' => $session->scheduled_date->toDateString(),
        ], [
            'availability_type' => 'available',
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
    }
}
