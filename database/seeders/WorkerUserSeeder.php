<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AvailabilityType;
use App\Enums\UserModuleType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkerZone;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;

final class WorkerUserSeeder extends Seeder
{
    private const string WorkerEmail = 'worker@dllni.sy';

    private const string WorkerPhone = '+963944100010';

    private const string Password = 'password';

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::WorkerEmail],
            [
                'name' => 'عامل التنظيف',
                'phone' => self::WorkerPhone,
                'module_type' => UserModuleType::CleaningWorker,
                'password' => bcrypt(self::Password),
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'phone' => self::WorkerPhone,
            'module_type' => UserModuleType::CleaningWorker,
            'phone_verified_at' => now(),
        ])->save();

        $worker = Worker::firstOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => 'عامل',
                'bio' => 'عامل تنظيف للاستخدام في اختبارات الواجهة البرمجية والتطبيق.',
                'average_rating' => 4.6,
                'total_completed_jobs' => 50,
                'trust_score' => 88,
                'acceptance_rate' => 92.0,
                'cancellation_rate' => 2.0,
                'open_disputes_count' => 0,
                'is_active' => true,
                'is_suspended' => false,
                'home_address' => 'ح�"ب - ا�"أشرف�Sة - شارع ا�"حدائ�,',
                'home_latitude' => 36.2308,
                'home_longitude' => 37.1279,
                'default_working_hours' => [
                    'sunday' => ['available' => false, 'data' => []],
                    'monday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'tuesday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'wednesday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'thursday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'friday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'saturday' => ['available' => true, 'data' => [['10:00' => '16:00']]],
                ],
            ]
        );

        WorkerZone::firstOrCreate(
            ['worker_id' => $worker->id, 'name' => 'ح�"ب - ا�"أشرف�Sة'],
            [
                'polygon' => [
                    ['lat' => 36.2190, 'lng' => 37.1140],
                    ['lat' => 36.2420, 'lng' => 37.1140],
                    ['lat' => 36.2420, 'lng' => 37.1410],
                    ['lat' => 36.2190, 'lng' => 37.1410],
                ],
                'is_active' => true,
            ]
        );

        for ($i = 0; $i < 7; $i++) {
            WorkerAvailability::firstOrCreate(
                [
                    'worker_id' => $worker->id,
                    'availability_date' => now()->addDays($i),
                ],
                [
                    'availability_type' => AvailabilityType::Available->value,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                ]
            );
        }

        SeederMedia::ensureSingleMedia(
            $worker,
            'avatar',
            'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=512&q=80',
            "worker-{$worker->id}-avatar"
        );

        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            'https://images.unsplash.com/photo-1541534401786-2077eed87a72?auto=format&fit=crop&w=600&q=80',
            "worker-user-{$user->id}-primary"
        );
    }
}
