<?php

declare(strict_types=1);

namespace Database\Seeders\Permissions;

use Illuminate\Database\Seeder;
use Mrmarchone\LaravelAutoCrud\Helpers\PermissionNameResolver;
use Spatie\Permission\Models\Permission;

final class SmRecurringOrderItemPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $group = 'recurring_order_items';
        $actions = ['view'];

        foreach ($actions as $action) {
            $permissionName = PermissionNameResolver::resolve($group, $action);
            Permission::firstOrCreate(['name' => $permissionName]);
        }
    }
}
