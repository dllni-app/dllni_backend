<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Database\Seeder;

final class RequestedTestUsersSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, phone: string, password: string, module_type: UserModuleType|null}>
     */
    private const USERS = [
        [
            'name' => 'Cleaning Test User',
            'phone' => '+963944100001',
            'password' => 'password',
            'module_type' => UserModuleType::CleaningWorker,
        ],
        [
            'name' => 'Restaurant Test User',
            'phone' => '+963944100002',
            'password' => 'password',
            'module_type' => UserModuleType::RestaurantSeller,
        ],
        [
            'name' => 'Supermarket Test User',
            'phone' => '+963944100003',
            'password' => 'password',
            'module_type' => UserModuleType::SupermarketSeller,
        ],
        [
            'name' => 'Delivery Test User',
            'phone' => '+963900000001',
            'password' => 'secret123',
            'module_type' => UserModuleType::DeliveryDriver,
        ],
    ];

    public function run(): void
    {
        foreach (self::USERS as $profile) {
            User::updateOrCreate(
                ['phone' => $profile['phone']],
                [
                    'name' => $profile['name'],
                    'module_type' => $profile['module_type']?->value,
                    'password' => bcrypt($profile['password']),
                    'phone_verified_at' => now(),
                ]
            );
        }
    }
}
