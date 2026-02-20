<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionGroup;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class DashboardPermissionsSeeder extends Seeder
{
    private const string AdminRoleName = 'admin';

    private const array Actions = ['view', 'create', 'update', 'delete'];

    public function run(): void
    {
        $guardName = config('auth.defaults.guard');

        foreach (PermissionGroup::cases() as $group) {
            foreach (self::Actions as $action) {
                $name = "{$group->value}.{$action}";
                Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => $guardName]
                );
            }
        }

        $adminRole = Role::firstOrCreate(
            ['name' => self::AdminRoleName, 'guard_name' => $guardName]
        );

        $adminRole->syncPermissions(Permission::where('guard_name', $guardName)->pluck('name'));
    }
}
