<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AvailabilityType;
use App\Enums\GenderPreference;
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
                'gender' => GenderPreference::Female->value,
                'first_name' => 'سارة',
                'bio' => 'عاملة تنظيف محترفة بخبرة أكثر من 5 سنوات، متخصصة في التنظيف العميق واستخدام المنتجات الصديقة للبيئة.',
                'address' => 'حلب - الجميلية - شارع فيصل',
                'lat' => 36.2127,
                'lng' => 37.1456,
            ],
            [
                'email' => 'worker2@dllni.sy',
                'gender' => GenderPreference::Male->value,
                'first_name' => 'أحمد',
                'bio' => 'عامل موثوق ومنضبط، لديه خبرة في مساعدة المناسبات والتجمعات الكبيرة.',
                'address' => 'حلب - الحمدانية - شارع القدس',
                'lat' => 36.1795,
                'lng' => 37.1082,
            ],
            [
                'email' => 'worker3@dllni.sy',
                'gender' => GenderPreference::Female->value,
                'first_name' => 'ليلى',
                'bio' => 'عاملة تنظيف تهتم بالتفاصيل، متاحة للتنظيف الدوري والتنظيف لمرة واحدة.',
                'address' => 'حلب - السريان الجديدة - شارع تشرين',
                'lat' => 36.2168,
                'lng' => 37.1317,
            ],
        ];

        foreach ($workers as $index => $data) {
            $phone = sprintf('+9639441201%02d', $index + 1);

            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['first_name'].' عامل تنظيف',
                    'phone' => $phone,
                    'module_type' => UserModuleType::CleaningWorker,
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );

            $user->forceFill([
                'name' => $data['first_name'].' عامل تنظيف',
                'phone' => $phone,
                'module_type' => UserModuleType::CleaningWorker,
                'phone_verified_at' => now(),
            ])->save();

            $worker = Worker::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name' => $data['first_name'],
                    'gender' => $data['gender'],
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
                    'default_working_hours' => self::defaultWorkingHours(),
                ]
            );

            $worker->forceFill([
                'first_name' => $data['first_name'],
                'gender' => $data['gender'],
                'bio' => $data['bio'],
                'is_active' => true,
                'is_suspended' => false,
                'home_address' => $data['address'],
                'home_latitude' => $data['lat'],
                'home_longitude' => $data['lng'],
                'default_working_hours' => self::defaultWorkingHours(),
            ])->save();

            WorkerZone::updateOrCreate(
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
                'https://images.unsplash.com/photo-1494790108377-be9c29b29330?auto=format&fit=crop&w=512&q=80',
                "worker-{$worker->id}-avatar"
            );
        }
    }

    private static function defaultWorkingHours(): array
    {
        return [
            'monday' => ['09:00', '18:00'],
            'tuesday' => ['09:00', '18:00'],
            'wednesday' => ['09:00', '18:00'],
            'thursday' => ['09:00', '18:00'],
            'friday' => ['09:00', '18:00'],
            'saturday' => ['10:00', '16:00'],
        ];
    }
}
