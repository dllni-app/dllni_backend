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

final class WorkerSeeder extends Seeder
{
    public function run(): void
    {
        $workers = [
            [
                'email' => 'worker1@dllni.sy',
                'first_name' => 'سارة',
                'bio' => '�.�?ظفة �.حترفة بخبرة أ�fثر �.�? 5 س�?�^ات. �.تخصصة ف�S ا�"ت�?ظ�Sف ا�"ع�.�S�, �^ا�"�.�?تجات ا�"صد�S�,ة �"�"ب�Sئة.',
                'address' => 'ح�"ب - ا�"ج�.�S�"�Sة - شارع ف�Sص�"',
                'lat' => 36.2127,
                'lng' => 37.1456,
            ],
            [
                'email' => 'worker2@dllni.sy',
                'first_name' => 'أح�.د',
                'bio' => '�.�^ث�^�, �^�.�?ضبط. ذ�^ خبرة ف�S �.ساعدة ا�"�.�?اسبات �^ا�"تج�.عات ا�"�fب�Sرة.',
                'address' => 'ح�"ب - ا�"ح�.دا�?�Sة - شارع ا�"�,دس',
                'lat' => 36.1795,
                'lng' => 37.1082,
            ],
            [
                'email' => 'worker3@dllni.sy',
                'first_name' => '�"�S�"�?',
                'bio' => '�.�?ظفة ت�?ت�. با�"تفاص�S�". �.تاحة �"�"ت�?ظ�Sف ا�"د�^ر�S �^ا�"�.رة ا�"�^احدة.',
                'address' => 'ح�"ب - ا�"سر�Sا�? ا�"جد�Sدة - شارع تشر�S�?',
                'lat' => 36.2168,
                'lng' => 37.1317,
            ],
        ];

        foreach ($workers as $index => $data) {
            $phone = sprintf('+9639441201%02d', $index + 1);

            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['first_name'] . ' عا�.�"',
                    'phone' => $phone,
                    'module_type' => UserModuleType::CleaningWorker,
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );

            $user->forceFill([
                'phone' => $phone,
                'module_type' => UserModuleType::CleaningWorker,
                'phone_verified_at' => now(),
            ])->save();

            $worker = Worker::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $data['first_name'],
                    'bio' => $data['bio'],
                    'average_rating' => fake()->randomFloat(2, 4.2, 5.0),
                    'total_completed_jobs' => fake()->numberBetween(50, 300),
                    'trust_score' => fake()->numberBetween(80, 100),
                    'acceptance_rate' => fake()->randomFloat(2, 85, 99),
                    'cancellation_rate' => fake()->randomFloat(2, 0, 5),
                    'open_disputes_count' => 0,
                    'is_active' => true,
                    'is_suspended' => false,
                    'home_address' => $data['address'],
                    'home_latitude' => $data['lat'],
                    'home_longitude' => $data['lng'],
                    'default_working_hours' => [
                        'monday' => ['09:00', '18:00'],
                        'tuesday' => ['09:00', '18:00'],
                        'wednesday' => ['09:00', '18:00'],
                        'thursday' => ['09:00', '18:00'],
                        'friday' => ['09:00', '18:00'],
                        'saturday' => ['10:00', '16:00'],
                    ],
                ]
            );

            WorkerZone::firstOrCreate(
                ['worker_id' => $worker->id, 'name' => 'ا�"�.�?ط�,ة ا�"أساس�Sة'],
                [
                    'polygon' => [
                        ['lat' => $data['lat'] - 0.05, 'lng' => $data['lng'] - 0.05],
                        ['lat' => $data['lat'] + 0.05, 'lng' => $data['lng'] - 0.05],
                        ['lat' => $data['lat'] + 0.05, 'lng' => $data['lng'] + 0.05],
                        ['lat' => $data['lat'] - 0.05, 'lng' => $data['lng'] + 0.05],
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
                'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=512&q=80',
                "worker-{$worker->id}-avatar"
            );
        }
    }
}
