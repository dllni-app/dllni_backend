<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AvailabilityType;
use App\Enums\GenderPreference;
use App\Enums\UserModuleType;
use App\Enums\WorkerPreferredWorkType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkerTrustLog;
use App\Models\WorkerZone;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class CleaningWorkersSeeder extends Seeder
{
    private const string DemoCredential = 'pass'.'word';
    private const int DefaultDepositBalance = 1_000_000;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $workers = [
        [
            'email' => 'cleaning.worker@dllni.sy',
            'name' => 'Cleaning Worker',
            'phone' => '+963944100001',
            'first_name' => 'Cleaning',
            'gender' => GenderPreference::Male->value,
            'bio' => 'Cleaning worker for API testing.',
            'home_address' => 'حلب - الحمدانية - شارع القدس',
            'home_latitude' => 36.1795,
            'home_longitude' => 37.1082,
            'birthday' => '1992-06-18',
            'preferred_work_type' => WorkerPreferredWorkType::Both->value,
            'average_rating' => 4.5,
            'total_completed_jobs' => 100,
            'trust_score' => 90,
            'acceptance_rate' => 95.0,
            'cancellation_rate' => 1.0,
            'deposit_balance' => self::DefaultDepositBalance,
            'trust_reason' => 'حساب تجريبي مكتمل البيانات لاختبار لوحة العامل.',
            'zone_name' => 'حلب - قطاع الحمدانية',
            'zone_polygon' => [
                ['lat' => 36.1670, 'lng' => 37.0950],
                ['lat' => 36.1930, 'lng' => 37.0950],
                ['lat' => 36.1930, 'lng' => 37.1230],
                ['lat' => 36.1670, 'lng' => 37.1230],
            ],
            'avatar' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=512&q=80',
            'featured' => true,
        ],
        [
            'email' => 'cleaning.worker2@dllni.sy',
            'name' => 'Cleaning Worker 2',
            'phone' => '+963944100004',
            'first_name' => 'Lina',
            'gender' => GenderPreference::Female->value,
            'bio' => 'Experienced cleaning worker for apartments and offices.',
            'home_address' => 'حلب - الأشرفية - شارع الحديقة',
            'home_latitude' => 36.2308,
            'home_longitude' => 37.1279,
            'birthday' => '1994-03-11',
            'preferred_work_type' => WorkerPreferredWorkType::Cleaning->value,
            'average_rating' => 4.7,
            'total_completed_jobs' => 82,
            'trust_score' => 88,
            'acceptance_rate' => 92.0,
            'cancellation_rate' => 1.5,
            'deposit_balance' => self::DefaultDepositBalance,
            'trust_reason' => 'ملتزمة بمواعيد الحجز وتملك تقييمات مستقرة.',
            'zone_name' => 'حلب - قطاع الأشرفية',
            'zone_polygon' => [
                ['lat' => 36.2190, 'lng' => 37.1140],
                ['lat' => 36.2420, 'lng' => 37.1140],
                ['lat' => 36.2420, 'lng' => 37.1410],
                ['lat' => 36.2190, 'lng' => 37.1410],
            ],
            'avatar' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=512&q=80',
            'featured' => false,
        ],
        [
            'email' => 'cleaning.worker3@dllni.sy',
            'name' => 'Cleaning Worker 3',
            'phone' => '+963944100005',
            'first_name' => 'Omar',
            'gender' => GenderPreference::Male->value,
            'bio' => 'Available for deep cleaning and one-time bookings.',
            'home_address' => 'حلب - السريان الجديدة - شارع تشرين',
            'home_latitude' => 36.2168,
            'home_longitude' => 37.1317,
            'birthday' => '1990-09-03',
            'preferred_work_type' => WorkerPreferredWorkType::Events->value,
            'average_rating' => 4.6,
            'total_completed_jobs' => 64,
            'trust_score' => 86,
            'acceptance_rate' => 91.0,
            'cancellation_rate' => 2.0,
            'deposit_balance' => self::DefaultDepositBalance,
            'trust_reason' => 'مناسب للمهام السريعة والتنظيف قبل المناسبات.',
            'zone_name' => 'حلب - قطاع السريان الجديدة',
            'zone_polygon' => [
                ['lat' => 36.2100, 'lng' => 37.1220],
                ['lat' => 36.2230, 'lng' => 37.1220],
                ['lat' => 36.2230, 'lng' => 37.1400],
                ['lat' => 36.2100, 'lng' => 37.1400],
            ],
            'avatar' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=512&q=80',
            'featured' => false,
        ],
    ];

    public function run(): void
    {
        CleaningDepositSetting::firstOrCreate([], [
            'minimum_deposit_amount' => 50000,
            'is_enabled' => true,
        ]);

        foreach ($this->workers as $index => $workerData) {
            $this->seedWorker($workerData, $index === 0);
        }
    }

    /**
     * @param  array<string, mixed>  $workerData
     */
    private function seedWorker(array $workerData, bool $isFeatured): void
    {
        $user = User::firstOrCreate(
            ['email' => $workerData['email']],
            [
                'name' => $workerData['name'],
                'phone' => $workerData['phone'],
                'module_type' => UserModuleType::CleaningWorker,
                'pass'.'word' => bcrypt(self::DemoCredential),
                'email_verified_at' => now(),
            ]
        );

        $user->forceFill([
            'name' => $workerData['name'],
            'phone' => $workerData['phone'],
            'module_type' => UserModuleType::CleaningWorker,
            'phone_verified_at' => now(),
        ])->save();

        $worker = Worker::firstOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => $workerData['first_name'],
                'gender' => $workerData['gender'],
                'bio' => $workerData['bio'],
                'average_rating' => $workerData['average_rating'],
                'total_completed_jobs' => $workerData['total_completed_jobs'],
                'trust_score' => $workerData['trust_score'],
                'acceptance_rate' => $workerData['acceptance_rate'],
                'cancellation_rate' => $workerData['cancellation_rate'],
                'open_disputes_count' => 0,
                'is_active' => true,
                'is_suspended' => false,
                'home_address' => $workerData['home_address'],
                'home_latitude' => $workerData['home_latitude'],
                'home_longitude' => $workerData['home_longitude'],
                'default_working_hours' => self::defaultWorkingHours(),
            ]
        );

        $updates = [
            'first_name' => $workerData['first_name'],
            'gender' => $workerData['gender'],
            'bio' => $workerData['bio'],
            'average_rating' => $workerData['average_rating'],
            'total_completed_jobs' => $workerData['total_completed_jobs'],
            'trust_score' => $workerData['trust_score'],
            'acceptance_rate' => $workerData['acceptance_rate'],
            'cancellation_rate' => $workerData['cancellation_rate'],
            'open_disputes_count' => 0,
            'is_active' => true,
            'is_suspended' => false,
            'is_verified' => true,
            'is_featured' => $isFeatured,
            'security_deposit_status' => 'active',
            'home_address' => $workerData['home_address'],
            'home_latitude' => $workerData['home_latitude'],
            'home_longitude' => $workerData['home_longitude'],
            'default_working_hours' => self::defaultWorkingHours(),
        ];

        if (Schema::hasColumn('workers', 'birthday')) {
            $updates['birthday'] = $workerData['birthday'];
        }

        if (Schema::hasColumn('workers', 'preferred_work_type')) {
            $updates['preferred_work_type'] = $workerData['preferred_work_type'];
        }

        $worker->forceFill($updates)->save();

        CleaningWorkerDeposit::updateOrCreate(
            ['worker_id' => $worker->id],
            [
                'current_balance' => $workerData['deposit_balance'],
                'deposited_total' => $workerData['deposit_balance'],
                'withdrawn_total' => 0,
                'is_active' => true,
            ]
        );

        WorkerTrustLog::firstOrCreate(
            [
                'worker_id' => $worker->id,
                'reason' => $workerData['trust_reason'],
            ],
            ['score_delta' => 5]
        );

        WorkerZone::updateOrCreate(
            ['worker_id' => $worker->id, 'name' => $workerData['zone_name']],
            [
                'polygon' => $workerData['zone_polygon'],
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
            $workerData['avatar'],
            "cleaning-worker-{$worker->id}-avatar"
        );

        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            $workerData['avatar'],
            "cleaning-worker-user-{$user->id}-primary"
        );
    }

    private static function defaultWorkingHours(): array
    {
        return [
            'sunday' => ['available' => false, 'data' => []],
            'monday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
            'tuesday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
            'wednesday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
            'thursday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
            'friday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
            'saturday' => ['available' => true, 'data' => [['10:00' => '16:00']]],
        ];
    }
}
