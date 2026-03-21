<?php

declare(strict_types=1);

namespace Database\Seeders\Permissions;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

final class RestaurantOwnerEmployeePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $guardName = config('auth.defaults.guard');

        $definitions = [
            ['name' => 'ro.offers_coupons', 'slug' => 'عروض كوبونات'],
            ['name' => 'ro.staff_register', 'slug' => 'موظفين سجل'],
            ['name' => 'ro.store_hours', 'slug' => 'متجر ساعات'],
            ['name' => 'ro.warehouse', 'slug' => 'مخزن إشراف'],
            ['name' => 'ro.menu', 'slug' => 'وجبات تعديل'],
            ['name' => 'ro.orders', 'slug' => 'طلبات إدارة'],
        ];

        foreach ($definitions as $definition) {
            Permission::updateOrCreate(
                [
                    'name' => $definition['name'],
                    'guard_name' => $guardName,
                ],
                [
                    'slug' => $definition['slug'],
                    'group' => 'restaurant_owner',
                ]
            );
        }
    }
}
