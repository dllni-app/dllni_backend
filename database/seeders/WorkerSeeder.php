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
                'email' => 'worker1@example.com',
                'first_name' => 'سارة',
                'bio' => 'منظفة محترفة بخبرة أكثر من 5 سنوات. متخصصة في التنظيف العميق والمنتجات الصديقة للبيئة.',
                'address' => '123 الشارع الرئيسي، وسط البلد',
                'lat' => 31.963158,
                'lng' => 35.930359,
            ],
            [
                'email' => 'worker2@example.com',
                'first_name' => 'أحمد',
                'bio' => 'موثوق ومنضبط. ذو خبرة في مساعدة المناسبات والتجمعات الكبيرة.',
                'address' => '456 جادة البلوط، الحي الشمالي',
                'lat' => 31.970000,
                'lng' => 35.940000,
            ],
            [
                'email' => 'worker3@example.com',
                'first_name' => 'ليلى',
                'bio' => 'منظفة تهتم بالتفاصيل. متاحة للتنظيف الدوري والمرة الواحدة.',
                'address' => '789 طريق الصنوبر، الجانب الشرقي',
                'lat' => 31.955000,
                'lng' => 35.920000,
            ],
        ];

        foreach ($workers as $index => $data) {
            $phone = sprintf('+9627900001%02d', $index + 1);

            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['first_name'] . ' عامل',
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
                ['worker_id' => $worker->id, 'name' => 'المنطقة الأساسية'],
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
                "https://picsum.photos/seed/worker-{$worker->id}-avatar/512/512",
                "worker-{$worker->id}-avatar"
            );
        }
    }
}
