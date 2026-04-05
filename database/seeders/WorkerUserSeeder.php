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
    private const string WorkerEmail = 'worker@example.com';

    private const string WorkerPhone = '+962790000010';

    private const string Password = 'password';

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::WorkerEmail],
            [
                'name' => 'Worker User',
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
                'first_name' => 'Worker',
                'bio' => 'Cleaning worker for API and app testing.',
                'average_rating' => 4.6,
                'total_completed_jobs' => 50,
                'trust_score' => 88,
                'acceptance_rate' => 92.0,
                'cancellation_rate' => 2.0,
                'open_disputes_count' => 0,
                'is_active' => true,
                'is_suspended' => false,
                'home_address' => '456 Worker Ave',
                'home_latitude' => 31.96,
                'home_longitude' => 35.93,
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
            ['worker_id' => $worker->id, 'name' => 'Amman Central'],
            [
                'polygon' => [
                    ['lat' => 31.91, 'lng' => 35.88],
                    ['lat' => 31.99, 'lng' => 35.88],
                    ['lat' => 31.99, 'lng' => 35.98],
                    ['lat' => 31.91, 'lng' => 35.98],
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
            "https://picsum.photos/seed/worker-{$worker->id}-avatar/512/512",
            "worker-{$worker->id}-avatar"
        );

        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            "https://picsum.photos/seed/worker-user-{$user->id}-primary/600/600",
            "worker-user-{$user->id}-primary"
        );
    }
}
