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
use Modules\Resturants\Enums\PriceRange;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmStore;

final class CleaningWorkerAndSellerSeeder extends Seeder
{
    private const string CleaningWorkerEmail = 'cleaning.worker@example.com';

    private const string CleaningWorkerPhone = '+962790000001';

    private const string RestaurantSellerEmail = 'seller@example.com';

    private const string RestaurantSellerPhone = '+962790000002';

    private const string SupermarketSellerEmail = 'supermarket.seller@example.com';

    private const string SupermarketSellerPhone = '+962790000003';

    private const string Password = 'password';

    public function run(): void
    {
        $this->seedCleaningWorkerUser();
        $this->seedRestaurantSellerUser();
        $this->seedSupermarketSellerUser();
    }

    private function seedCleaningWorkerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::CleaningWorkerEmail],
            [
                'name' => 'Cleaning Worker',
                'phone' => self::CleaningWorkerPhone,
                'module_type' => UserModuleType::CleaningWorker,
                'password' => bcrypt(self::Password),
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'phone' => self::CleaningWorkerPhone,
            'module_type' => UserModuleType::CleaningWorker,
            'phone_verified_at' => now(),
        ])->save();

        $worker = Worker::firstOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => 'Cleaning',
                'bio' => 'Cleaning worker for API testing.',
                'average_rating' => 4.5,
                'total_completed_jobs' => 100,
                'trust_score' => 90,
                'acceptance_rate' => 95.0,
                'cancellation_rate' => 1.0,
                'open_disputes_count' => 0,
                'is_active' => true,
                'is_suspended' => false,
                'home_address' => '123 Worker St',
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
            ['worker_id' => $worker->id, 'name' => 'Default Zone'],
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
            "https://picsum.photos/seed/cleaning-worker-user-{$user->id}-primary/600/600",
            "cleaning-worker-user-{$user->id}-primary"
        );
    }

    private function seedRestaurantSellerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::RestaurantSellerEmail],
            [
                'name' => 'Restaurant Seller',
                'phone' => self::RestaurantSellerPhone,
                'module_type' => UserModuleType::RestaurantSeller,
                'password' => bcrypt(self::Password),
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'phone' => self::RestaurantSellerPhone,
            'module_type' => UserModuleType::RestaurantSeller,
            'phone_verified_at' => now(),
        ])->save();

        if (Restaurant::where('user_id', $user->id)->exists()) {
            return;
        }

        Restaurant::create([
            'user_id' => $user->id,
            'name' => 'Seller Restaurant',
            'slug' => 'seller-restaurant-' . mb_substr(hash('sha256', (string) $user->id), 0, 8),
            'description' => 'Restaurant owned by seller user for API testing.',
            'address' => '456 Seller Ave',
            'latitude' => 31.965,
            'longitude' => 35.932,
            'phone' => '+962 6 555 0000',
            'email' => 'seller@restaurant.example.com',
            'average_rating' => 4.0,
            'total_reviews' => 0,
            'estimated_preparation_time' => 20,
            'minimum_order_amount' => 10.0,
            'price_range' => PriceRange::Medium->value,
            'reputation_score' => 85,
            'visibility_score' => 100,
            'is_active' => true,
            'is_featured' => false,
        ]);
    }

    private function seedSupermarketSellerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::SupermarketSellerEmail],
            [
                'name' => 'Supermarket Seller',
                'phone' => self::SupermarketSellerPhone,
                'module_type' => UserModuleType::SupermarketSeller,
                'password' => bcrypt(self::Password),
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'phone' => self::SupermarketSellerPhone,
            'module_type' => UserModuleType::SupermarketSeller,
            'phone_verified_at' => now(),
        ])->save();

        if (SmStore::where('owner_user_id', $user->id)->exists()) {
            return;
        }

        SmStore::create([
            'owner_user_id' => $user->id,
            'name' => 'Seller Supermarket',
            'slug' => 'seller-supermarket-' . mb_substr(hash('sha256', (string) $user->id), 0, 8),
            'description' => 'Supermarket owned by seller user for API testing.',
            'address' => '789 Store St',
            'latitude' => 31.97,
            'longitude' => 35.94,
            'phone' => '+962 6 555 0001',
            'email' => 'seller@supermarket.example.com',
            'average_rating' => 4.0,
            'total_reviews' => 0,
            'trust_score' => 85,
            'warning_count' => 0,
            'is_active' => true,
            'is_featured' => false,
        ]);
    }
}
