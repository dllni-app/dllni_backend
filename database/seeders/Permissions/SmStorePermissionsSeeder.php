<?php

declare(strict_types=1);

namespace Database\Seeders\Permissions;

use Illuminate\Database\Seeder;
use Mrmarchone\LaravelAutoCrud\Helpers\PermissionNameResolver;
use Spatie\Permission\Models\Permission;

final class SmStorePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $group = 'stores';
        $actions = ['view', 'create', 'update', 'delete'];

        foreach ($actions as $action) {
            $permissionName = PermissionNameResolver::resolve($group, $action);
            Permission::firstOrCreate(['name' => $permissionName]);
        }
    }
}
