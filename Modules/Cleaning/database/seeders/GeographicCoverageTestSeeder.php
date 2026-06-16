<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Enums\UserModuleType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerZone;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class GeographicCoverageTestSeeder extends Seeder
{
    public function run(): void
    {
        // Aleppo-focused distribution with uneven worker coverage.
        // This intentionally creates high-demand pressure for low-worker zones.
        $workersByZone = [
            'الجميلية' => 3,
            'الحمدانية' => 2,
            'الفرقان' => 2,
            'السليمانية' => 1,
            'الموكامبو' => 1,
            'الأشرفية' => 1,
            'صلاح الدين' => 1,
        ];

        $workerIds = [];
        $workerIndex = 1;

        foreach ($workersByZone as $zoneName => $count) {
            for ($i = 0; $i < $count; $i++) {
                $worker = $this->upsertWorker($workerIndex);
                $workerIds[] = $worker->id;

                WorkerZone::updateOrCreate(
                    [
                        'worker_id' => $worker->id,
                        'name' => $zoneName,
                    ],
                    [
                        'polygon' => null,
                        'is_active' => true,
                    ]
                );

                $workerIndex++;
            }
        }

        // Ensure inactive zones do not affect geographic coverage KPI.
        $firstWorkerId = $workerIds[0] ?? null;
        if ($firstWorkerId !== null) {
            WorkerZone::updateOrCreate(
                [
                    'worker_id' => $firstWorkerId,
                    'name' => 'منطقة غير فعالة',
                ],
                [
                    'polygon' => null,
                    'is_active' => false,
                ]
            );
        }

        $customer = User::updateOrCreate(
            ['email' => 'coverage.customer@dllni.sy'],
            [
                'name' => 'عميل اختبار التغطية',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $this->assignUniquePhone($customer, 990001);

        // Active demand (next 7 days + active statuses) for "high demand zones" card.
        $this->seedActiveDemandBookings($customer->id, $workerIds, 'apartment', 5);
        $this->seedActiveDemandBookings($customer->id, $workerIds, 'villa', 3);
        $this->seedActiveDemandBookings($customer->id, $workerIds, 'office', 2);

        // Extra non-active bookings to enrich chart/table dataset without inflating demand KPI.
        $this->seedNonActiveBookings($customer->id, $workerIds, 'apartment', 8);
        $this->seedNonActiveBookings($customer->id, $workerIds, 'villa', 5);
        $this->seedNonActiveBookings($customer->id, $workerIds, 'office', 4);
        $this->seedNonActiveBookings($customer->id, $workerIds, 'studio', 4);
    }

    private function upsertWorker(int $index): Worker
    {
        $email = "coverage.worker{$index}@dllni.sy";
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => "عامل تغطية {$index}",
                'module_type' => UserModuleType::CleaningWorker,
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $this->assignUniquePhone($user, 990100 + $index);

        $user->forceFill([
            'module_type' => UserModuleType::CleaningWorker,
            'phone_verified_at' => now(),
        ])->save();

        return Worker::updateOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => "عامل {$index}",
                'bio' => 'بيانات اختبار واقعية لتغطية مناطق مدينة حلب.',
                'average_rating' => 4.5,
                'total_completed_jobs' => 120 + $index,
                'trust_score' => 90,
                'acceptance_rate' => 95,
                'cancellation_rate' => 2,
                'open_disputes_count' => 0,
                'is_active' => true,
                'is_suspended' => false,
                'home_address' => 'حلب - سوريا',
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
    }

    /**
     * @param  array<int, int>  $workerIds
     */
    private function seedActiveDemandBookings(int $customerId, array $workerIds, string $propertyType, int $count): void
    {
        $statuses = [
            CleaningBookingStatus::Pending->value,
            CleaningBookingStatus::WorkerAssigned->value,
            CleaningBookingStatus::InProgress->value,
        ];

        for ($i = 1; $i <= $count; $i++) {
            $bookingNumber = sprintf('GEO-ALEPPO-ACTIVE-%s-%03d', strtoupper($propertyType), $i);

            CleaningBooking::updateOrCreate(
                ['booking_number' => $bookingNumber],
                [
                    'customer_id' => $customerId,
                    'worker_id' => $workerIds[($i - 1) % count($workerIds)] ?? null,
                    'booking_number' => $bookingNumber,
                    'status' => $statuses[($i - 1) % count($statuses)],
                    'property_type' => $propertyType,
                    'property_details' => [
                        'city' => 'Aleppo',
                        'country' => 'Syria',
                        'source' => 'geographic_coverage_aleppo_seed_active',
                    ],
                    'scheduled_date' => now()->addDays($i % 6)->toDateString(),
                    'scheduled_time' => '10:00',
                    'total_hours' => 3,
                    'base_price' => 70,
                    'addons_total' => 0,
                    'travel_fee' => 10,
                    'cancellation_fee' => 0,
                    'total_price' => 80,
                    'terms_accepted' => true,
                ]
            );
        }
    }

    /**
     * @param  array<int, int>  $workerIds
     */
    private function seedNonActiveBookings(int $customerId, array $workerIds, string $propertyType, int $count): void
    {
        $statuses = [
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::Cancelled->value,
        ];

        for ($i = 1; $i <= $count; $i++) {
            $bookingNumber = sprintf('GEO-ALEPPO-HIST-%s-%03d', strtoupper($propertyType), $i);

            CleaningBooking::updateOrCreate(
                ['booking_number' => $bookingNumber],
                [
                    'customer_id' => $customerId,
                    'worker_id' => $workerIds[($i - 1) % count($workerIds)] ?? null,
                    'booking_number' => $bookingNumber,
                    'status' => $statuses[($i - 1) % count($statuses)],
                    'property_type' => $propertyType,
                    'property_details' => [
                        'city' => 'Aleppo',
                        'country' => 'Syria',
                        'source' => 'geographic_coverage_aleppo_seed_history',
                    ],
                    'scheduled_date' => now()->subDays(7 + $i)->toDateString(),
                    'scheduled_time' => '12:00',
                    'total_hours' => 4,
                    'base_price' => 85,
                    'addons_total' => 10,
                    'travel_fee' => 12,
                    'cancellation_fee' => 0,
                    'total_price' => 107,
                    'terms_accepted' => true,
                ]
            );
        }
    }

    private function assignUniquePhone(User $user, int $baseSuffix): void
    {
        $suffix = $baseSuffix;
        while (true) {
            $candidate = '+9639'.str_pad((string) $suffix, 7, '0', STR_PAD_LEFT);
            $exists = User::query()
                ->where('phone', $candidate)
                ->where('id', '!=', $user->id)
                ->exists();

            if (! $exists) {
                $user->forceFill(['phone' => $candidate])->save();

                return;
            }

            $suffix++;
        }
    }
}
