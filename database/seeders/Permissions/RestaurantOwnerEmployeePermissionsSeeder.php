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
            [
                'name' => 'ro.offers_coupons',
                'slug' => 'ادارة العروض والكوبونات',
                'description' => 'يمكنه التحكم باقسام العروض والكوبونات',
            ],
            [
                'name' => 'ro.staff_register',
                'slug' => 'ادارة الموظفين',
                'description' => 'اضافة موظفين وتعديل بياناتهم ومراقبة السجل التجاري الخاص بهم',
            ],
            [
                'name' => 'ro.store_hours',
                'slug' => 'ادارة بيانات المتجر',
                'description' => 'تعديل بيانات المتجر وساعات العمل',
            ],
            [
                'name' => 'ro.warehouse',
                'slug' => 'ادارة المخزن',
                'description' => 'الاشراف على المخزن وتنظيم عمله',
            ],
            [
                'name' => 'ro.menu',
                'slug' => 'تعديل الوجبات',
                'description' => 'اضافة الوجبات وتعديل بياناتها',
            ],
            [
                'name' => 'ro.orders',
                'slug' => 'ادارة الطلبات',
                'description' => 'ادارة الطلبات',
            ],
        ];

        foreach ($definitions as $definition) {
            Permission::updateOrCreate(
                [
                    'name' => $definition['name'],
                    'guard_name' => $guardName,
                ],
                [
                    'slug' => $definition['slug'],
                    'description' => $definition['description'],
                    'group' => 'restaurant_owner',
                ]
            );
        }
    }
}
