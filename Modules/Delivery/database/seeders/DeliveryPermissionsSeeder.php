<?php

declare(strict_types=1);

namespace Modules\Delivery\Database\Seeders;

use App\Enums\PermissionGroup;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class DeliveryPermissionsSeeder extends Seeder
{
    private const array DeliveryPermissionGroups = [
        PermissionGroup::DeliveryCompanies,
        PermissionGroup::DeliveryDrivers,
        PermissionGroup::DeliveryOrders,
        PermissionGroup::DeliveryDisputes,
        PermissionGroup::DeliveryFinancial,
        PermissionGroup::DeliveryReports,
    ];

    private const array Actions = ['view', 'create', 'update', 'delete'];

    private const string CompanyAdminRoleName = 'delivery_company_admin';

    private const string CompanyStaffRoleName = 'delivery_company_staff';

    public function run(): void
    {
        $guardName = config('auth.defaults.guard');

        // Create delivery permission groups
        foreach (self::DeliveryPermissionGroups as $group) {
            foreach (self::Actions as $action) {
                $name = "{$group->value}.{$action}";
                Permission::firstOrCreate(
                    ['name' => $name, 'guard_name' => $guardName]
                );
            }
        }

        // Create delivery company admin role with all permissions
        $companyAdminRole = Role::firstOrCreate(
            ['name' => self::CompanyAdminRoleName, 'guard_name' => $guardName]
        );

        $deliveryPermissions = Permission::query()
            ->where('guard_name', $guardName)
            ->whereIn('name', $this->getDeliveryPermissionNames())
            ->pluck('name');

        $companyAdminRole->syncPermissions($deliveryPermissions);

        // Create delivery company staff role with limited permissions
        $companyStaffRole = Role::firstOrCreate(
            ['name' => self::CompanyStaffRoleName, 'guard_name' => $guardName]
        );

        $staffPermissions = $this->getStaffPermissions();
        $staffPermissionModels = Permission::query()
            ->where('guard_name', $guardName)
            ->whereIn('name', $staffPermissions)
            ->pluck('name');

        $companyStaffRole->syncPermissions($staffPermissionModels);
    }

    private function getDeliveryPermissionNames(): array
    {
        $names = [];
        foreach (self::DeliveryPermissionGroups as $group) {
            foreach (self::Actions as $action) {
                $names[] = "{$group->value}.{$action}";
            }
        }

        return $names;
    }

    private function getStaffPermissions(): array
    {
        return [
            PermissionGroup::DeliveryOrders->value.'.view',
            PermissionGroup::DeliveryDrivers->value.'.view',
            PermissionGroup::DeliveryDisputes->value.'.view',
            PermissionGroup::DeliveryReports->value.'.view',
            PermissionGroup::DeliveryFinancial->value.'.view',
        ];
    }
}
