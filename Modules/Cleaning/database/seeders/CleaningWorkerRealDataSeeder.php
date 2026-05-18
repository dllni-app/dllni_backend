<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkerZone;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningWorkerRealDataSeeder extends Seeder
{
    private const string WorkerEmail = 'cleaning.worker2@dllni.sy';

    private const string WorkerPhone = '+963944120010';

    private const string Password = 'password';

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::WorkerEmail],
            [
                'name' => 'Cleaning Worker Real',
                'phone' => self::WorkerPhone,
                'password' => bcrypt(self::Password),
                'email_verified_at' => now(),
            ]
        );

        $user->forceFill([
            'phone' => self::WorkerPhone,
            'phone_verified_at' => now(),
        ])->save();

        $worker = Worker::firstOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => 'ليلى',
                'bio' => 'Experienced cleaner seeded for real-data testing.',
                'average_rating' => 4.7,
                'total_completed_jobs' => 78,
                'trust_score' => 88,
                'acceptance_rate' => 92.0,
                'cancellation_rate' => 1.5,
                'open_disputes_count' => 0,
                'is_active' => true,
                'is_suspended' => false,
                'home_address' => 'حلب - الجميلية - شارع النصر',
                'home_latitude' => 36.2127,
                'home_longitude' => 37.1456,
                'default_working_hours' => [
                    'sunday' => ['available' => true, 'data' => [['08:00' => '16:00']]],
                    'monday' => ['available' => true, 'data' => [['08:00' => '16:00']]],
                    'tuesday' => ['available' => true, 'data' => [['08:00' => '16:00']]],
                    'wednesday' => ['available' => true, 'data' => [['08:00' => '16:00']]],
                    'thursday' => ['available' => true, 'data' => [['08:00' => '16:00']]],
                    'friday' => ['available' => false, 'data' => []],
                    'saturday' => ['available' => false, 'data' => []],
                ],
            ]
        );

        WorkerZone::firstOrCreate(
            ['worker_id' => $worker->id, 'name' => 'حلب - قطاع الجميلية'],
            [
                'polygon' => [
                    ['lat' => 36.2050, 'lng' => 37.1350],
                    ['lat' => 36.2200, 'lng' => 37.1350],
                    ['lat' => 36.2200, 'lng' => 37.1500],
                    ['lat' => 36.2050, 'lng' => 37.1500],
                ],
                'is_active' => true,
            ]
        );

        for ($i = 0; $i < 5; $i++) {
            WorkerAvailability::firstOrCreate(
                [
                    'worker_id' => $worker->id,
                    'availability_date' => now()->addDays($i),
                ],
                [
                    'availability_type' => 'available',
                    'start_time' => '08:00',
                    'end_time' => '16:00',
                ]
            );
        }

        SeederMedia::ensureSingleMedia(
            $worker,
            'avatar',
            'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=512&q=80',
            "cleaning-worker-2-{$worker->id}-avatar"
        );

        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            'https://images.unsplash.com/photo-1502685104226-ee32379fefbe?auto=format&fit=crop&w=600&q=80',
            "cleaning-worker-user-2-{$user->id}-primary"
        );

        // Create a few customers and a booking each for testing
        $billingPolicy = CleaningBillingPolicy::where('is_default', true)->first();
        $cancellationPolicy = CancellationPolicy::where('module', 'cleaning')->where('is_default', true)->first();

        $customers = [
            ['name' => 'Samir Haddad', 'email' => 'samir.haddad@dllni.sy', 'phone' => '+963944130001'],
            ['name' => 'Maya Youssef', 'email' => 'maya.youssef@dllni.sy', 'phone' => '+963944130002'],
        ];

        foreach ($customers as $idx => $c) {
            $customer = User::firstOrCreate(
                ['email' => $c['email']],
                [
                    'name' => $c['name'],
                    'phone' => $c['phone'],
                    'password' => bcrypt(self::Password),
                    'email_verified_at' => now(),
                ]
            );

            $bookingNumber = 'CLN-REAL-' . str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT);
            if (! CleaningBooking::where('booking_number', $bookingNumber)->exists()) {
                CleaningBooking::create([
                    'customer_id' => $customer->id,
                    'worker_id' => $worker->id,
                    'cancellation_policy_id' => $cancellationPolicy?->id,
                    'billing_policy_id' => $billingPolicy?->id,
                    'booking_number' => $bookingNumber,
                    'status' => 'pending',
                    'property_type' => 'apartment',
                    'property_details' => [
                        'location_name' => 'شقة الضيف',
                        'address' => 'قرب السوق المركزي',
                        'bedrooms' => 2,
                        'rooms' => 3,
                        'bathrooms' => 1,
                        'kitchens' => 1,
                    ],
                    'estimated_sqm' => 80,
                    'estimated_hours' => 3,
                    'scheduled_date' => now()->addDays($idx + 1),
                    'scheduled_time' => '10:00',
                    'total_hours' => 3,
                    'base_price' => 60,
                    'addons_total' => 0,
                    'travel_fee' => 10,
                    'cancellation_fee' => 0,
                    'total_price' => 70,
                    'terms_accepted' => true,
                    'address_latitude' => 36.2127,
                    'address_longitude' => 37.1456,
                ]);
            }
        }
    }
}
