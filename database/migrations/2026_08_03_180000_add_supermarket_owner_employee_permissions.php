<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $guardName = config('auth.defaults.guard');
        $now = now();

        $definitions = [
            'so.products' => [
                'slug' => 'ادارة المنتجات',
                'description' => 'اضافة المنتجات وتعديل بياناتها',
                'prefixes' => ['products.'],
            ],
            'so.offers_coupons' => [
                'slug' => 'ادارة العروض والكوبونات',
                'description' => 'يمكنه التحكم باقسام العروض والكوبونات',
                'prefixes' => ['offers.', 'coupons.'],
            ],
            'so.orders' => [
                'slug' => 'ادارة الطلبات',
                'description' => 'ادارة الطلبات',
                'prefixes' => ['orders.'],
            ],
            'so.staff_register' => [
                'slug' => 'ادارة الموظفين',
                'description' => 'اضافة موظفين وتعديل بياناتهم ومراقبة سجل نشاطهم',
                'prefixes' => ['staff.'],
            ],
            'so.store_hours' => [
                'slug' => 'ادارة بيانات المتجر',
                'description' => 'تعديل بيانات المتجر وساعات العمل',
                'prefixes' => ['stores.'],
            ],
            'so.warehouse' => [
                'slug' => 'ادارة المخزن',
                'description' => 'الاشراف على المخزن وتنظيم عمله',
                'prefixes' => ['inventory.'],
            ],
        ];

        foreach ($definitions as $name => $definition) {
            DB::table('permissions')->updateOrInsert(
                [
                    'name' => $name,
                    'guard_name' => $guardName,
                ],
                [
                    'slug' => $definition['slug'],
                    'description' => $definition['description'],
                    'group' => 'supermarket_owner',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $permissionId = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', $guardName)
                ->value('id');

            if (! is_numeric($permissionId)) {
                continue;
            }

            $legacyPermissionIds = DB::table('permissions')
                ->where(function ($query) use ($definition): void {
                    foreach ($definition['prefixes'] as $prefix) {
                        $query->orWhere('name', 'like', $prefix.'%');
                    }
                })
                ->pluck('id');

            if ($legacyPermissionIds->isEmpty()) {
                continue;
            }

            $modelIds = DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->whereIn('permission_id', $legacyPermissionIds)
                ->distinct()
                ->pluck('model_id');

            foreach ($modelIds as $modelId) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => (int) $permissionId,
                    'model_type' => User::class,
                    'model_id' => $modelId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', [
                'so.products',
                'so.offers_coupons',
                'so.orders',
                'so.staff_register',
                'so.store_hours',
                'so.warehouse',
            ])
            ->pluck('id');

        DB::table('model_has_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::table('permissions')
            ->whereIn('id', $permissionIds)
            ->delete();
    }
};
