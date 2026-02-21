<?php

declare(strict_types=1);

namespace Database\Seeders\Permissions;

use Illuminate\Database\Seeder;
use Mrmarchone\LaravelAutoCrud\Helpers\PermissionNameResolver;
use Spatie\Permission\Models\Permission;

final class SmAssistantQueryPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $group = 'assistant_queries';
        $actions = ['view'];

        foreach ($actions as $action) {
            $permissionName = PermissionNameResolver::resolve($group, $action);
            Permission::firstOrCreate(['name' => $permissionName]);
        }
    }
}
