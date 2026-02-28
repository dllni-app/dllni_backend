<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class TeamRoleTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $guardName = config('auth.defaults.guard', 'web');

        $allPermissions = Permission::query()->where('guard_name', $guardName)->pluck('name');

        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => $guardName]);
        $superAdmin->syncPermissions($allPermissions);

        $opsPermissions = $this->permissionsByPrefixes($guardName, ['bookings.', 'disputes.', 'workers.', 'system_alerts.']);
        $ops = Role::firstOrCreate(['name' => 'Cleaning Ops Manager', 'guard_name' => $guardName]);
        $ops->syncPermissions($opsPermissions);

        $supportPermissions = $this->permissionsByPrefixes($guardName, ['bookings.view', 'bookings.update', 'disputes.view', 'system_alerts.view']);
        $support = Role::firstOrCreate(['name' => 'Customer Support', 'guard_name' => $guardName]);
        $support->syncPermissions($supportPermissions);

        $onboardingPermissions = $this->permissionsByPrefixes($guardName, ['workers.view', 'workers.update', 'workers.create']);
        $onboarding = Role::firstOrCreate(['name' => 'Onboarding Specialist', 'guard_name' => $guardName]);
        $onboarding->syncPermissions($onboardingPermissions);

        $accountantPermissions = $this->permissionsByPrefixes($guardName, ['reports.view', 'pricing.view']);
        $accountant = Role::firstOrCreate(['name' => 'Accountant', 'guard_name' => $guardName]);
        $accountant->syncPermissions($accountantPermissions);
    }

    private function permissionsByPrefixes(string $guardName, array $prefixes)
    {
        return Permission::query()
            ->where('guard_name', $guardName)
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    if (str_ends_with($prefix, '.')) {
                        $query->orWhere('name', 'like', $prefix.'%');
                    } else {
                        $query->orWhere('name', $prefix);
                    }
                }
            })
            ->pluck('name');
    }
}
