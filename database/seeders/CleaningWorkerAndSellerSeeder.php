<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AvailabilityType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkerZone;
use Illuminate\Database\Seeder;
use Modules\Resturants\Enums\PriceRange;
use Modules\Resturants\Models\Restaurant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class CleaningWorkerAndSellerSeeder extends Seeder
{
    private const string CleaningWorkerRoleName = 'cleaning_worker';

    private const string RestaurantSellerRoleName = 'restaurant_seller';

    private const array Actions = ['view', 'create', 'update', 'delete'];

    /**
     * Permissions for cleaning API endpoints (worker-facing, non-dashboard).
     *
     * @var array<string, list<string>>
     */
    private const array CleaningWorkerPermissionGroups = [
        'cleaning_bookings' => ['view', 'create', 'update', 'delete'],
        'event_bookings' => ['view', 'create', 'update', 'delete'],
        'cleaning_services' => ['view', 'create', 'update', 'delete'],
        'cleaning_time_warnings' => ['view'],
        'cleaning_billing_policies' => ['view', 'create', 'update', 'delete'],
        'service_pricing' => ['view', 'create', 'update', 'delete'],
        'worker_homepage' => ['view'],
        'geographic_coverage' => ['view'],
    ];

    /**
     * Permissions for restaurant seller API endpoints (non-dashboard).
     *
     * @var array<string, list<string>>
     */
    private const array RestaurantSellerPermissionGroups = [
        'seller_restaurants' => ['view', 'create', 'update', 'delete'],
        'seller_categories' => ['view', 'create', 'update', 'delete'],
        'seller_products' => ['view', 'create', 'update', 'delete'],
        'seller_orders' => ['view', 'create', 'update', 'delete'],
        'seller_offers' => ['view', 'create', 'update', 'delete'],
        'seller_promo_codes' => ['view', 'create', 'update', 'delete'],
        'seller_order_disputes' => ['view', 'create', 'update', 'delete'],
        'seller_documents' => ['view', 'create', 'update', 'delete'],
        'seller_reputation_logs' => ['view'],
        'seller_penalties' => ['view'],
        'seller_staff' => ['view'],
        'seller_roles' => ['view'],
        'seller_assistant_queries' => ['view'],
        'seller_recurring_orders' => ['view'],
        'seller_reviews' => ['view'],
    ];

    public function run(): void
    {
        $guardName = config('auth.defaults.guard');

        $cleaningPermissions = $this->createPermissions($guardName, self::CleaningWorkerPermissionGroups);
        $sellerPermissions = $this->createPermissions($guardName, self::RestaurantSellerPermissionGroups);

        $cleaningWorkerRole = Role::firstOrCreate(
            ['name' => self::CleaningWorkerRoleName, 'guard_name' => $guardName]
        );
        $cleaningWorkerRole->syncPermissions($cleaningPermissions);

        $restaurantSellerRole = Role::firstOrCreate(
            ['name' => self::RestaurantSellerRoleName, 'guard_name' => $guardName]
        );
        $restaurantSellerRole->syncPermissions($sellerPermissions);

        $this->seedCleaningWorkerUser();
        $this->seedSellerUser();
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return list<string>
     */
    private function createPermissions(string $guardName, array $groups): array
    {
        $names = [];
        foreach ($groups as $group => $actions) {
            foreach ($actions as $action) {
                $name = "{$group}.{$action}";
                Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => $guardName]
                );
                $names[] = $name;
            }
        }

        return $names;
    }

    private function seedCleaningWorkerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'cleaning.worker@example.com'],
            [
                'name' => 'Cleaning Worker',
                'phone' => '+962790000001',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole(self::CleaningWorkerRoleName)) {
            $user->assignRole(self::CleaningWorkerRoleName);
        }

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
    }

    private function seedSellerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'seller@example.com'],
            [
                'name' => 'Restaurant Seller',
                'phone' => '+962790000002',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole(self::RestaurantSellerRoleName)) {
            $user->assignRole(self::RestaurantSellerRoleName);
        }

        if (Restaurant::where('user_id', $user->id)->exists()) {
            return;
        }

        Restaurant::create([
            'user_id' => $user->id,
            'name' => 'Seller Restaurant',
            'slug' => 'seller-restaurant-'.mb_substr(md5((string) $user->id), 0, 8),
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
}
