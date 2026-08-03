<?php

declare(strict_types=1);

namespace Database\Seeders\Permissions;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

final class SupermarketOwnerEmployeePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $guardName = config('auth.defaults.guard');

        $definitions = [
            [
                'name' => 'so.products',
                'slug' => 'ادارة المنتجات',
                'description' => 'اضافة المنتجات وتعديل بياناتها',
            ],
            [
                'name' => 'so.offers_coupons',
                'slug' => 'ادارة العروض والكوبونات',
                'description' => 'يمكنه التحكم باقسام العروض والكوبونات',
            ],
            [
                'name' => 'so.orders',
                'slug' => 'ادارة الطلبات',
                'description' => 'ادارة الطلبات',
            ],
            [
                'name' => 'so.staff_register',
                'slug' => 'ادارة الموظفين',
                'description' => 'اضافة موظفين وتعديل بياناتهم ومراقبة سجل نشاطهم',
            ],
            [
                'name' => 'so.store_hours',
                'slug' => 'ادارة بيانات المتجر',
                'description' => 'تعديل بيانات المتجر وساعات العمل',
            ],
            [
                'name' => 'so.warehouse',
                'slug' => 'ادارة المخزن',
                'description' => 'الاشراف على المخزن وتنظيم عمله',
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
                    'group' => 'supermarket_owner',
                ]
            );
        }
    }
}
