<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;

final class RequestedTestUsersSeeder extends Seeder
{
    private const DELIVERY_PHONE = '+963900000001';

    /**
     * @var array<int, array{name: string, phone: string, password: string, module_type: UserModuleType|null, email?: string}>
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
            'phone' => self::DELIVERY_PHONE,
            'email' => 'mandoub.test@dllni.sy',
            'password' => 'secret123',
            'module_type' => UserModuleType::DeliveryDriver,
        ],
    ];

    public function run(): void
    {
        $users = [];

        foreach (self::USERS as $profile) {
            $attributes = [
                'name' => $profile['name'],
                'module_type' => $profile['module_type']?->value,
                'password' => bcrypt($profile['password']),
                'phone_verified_at' => now(),
                'is_active' => true,
            ];

            if (isset($profile['email'])) {
                $attributes['email'] = $profile['email'];
                $attributes['email_verified_at'] = now();
            }

            $users[$profile['phone']] = User::updateOrCreate(
                ['phone' => $profile['phone']],
                $attributes,
            )->fresh();
        }

        $this->ensureDeliveryDriverProfile($users[self::DELIVERY_PHONE]);
    }

    private function ensureDeliveryDriverProfile(User $deliveryUser): void
    {
        $company = DeliveryCompany::updateOrCreate(
            ['owner_user_id' => $deliveryUser->id],
            [
                'name' => 'Dllni Delivery',
                'legal_name' => 'Dllni Delivery',
                'phone' => self::DELIVERY_PHONE,
                'is_active' => true,
                'is_suspended' => false,
                'suspension_reason' => null,
                'suspended_until' => null,
            ]
        )->fresh();

        DeliveryDriver::updateOrCreate(
            ['user_id' => $deliveryUser->id],
            [
                'company_id' => $company->id,
                'first_name' => 'Delivery Test User',
                'phone' => self::DELIVERY_PHONE,
                'availability_status' => DeliveryDriverAvailabilityStatus::Available->value,
                'is_active' => true,
                'is_suspended' => false,
                'suspension_reason' => null,
                'suspended_until' => null,
            ]
        );
    }
}
