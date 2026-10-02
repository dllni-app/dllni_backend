<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private array $groups = [
        'restaurant_orders',
        'restaurants',
        'restaurant_disputes',
        'restaurant_catalog',
        'supermarket_orders',
        'supermarket_stores',
        'supermarket_catalog',
        'supermarket_disputes',
        'platform_delivery_operations',
        'platform_finance',
    ];

    /** @var list<string> */
    private array $actions = ['view', 'create', 'update', 'delete'];

    public function up(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        foreach ($this->groups as $group) {
            foreach ($this->actions as $action) {
                Permission::query()->firstOrCreate([
                    'name' => $group.'.'.$action,
                    'guard_name' => $guard,
                ]);
            }
        }

        $adminPermissions = Permission::query()
            ->where('guard_name', $guard)
            ->where(function ($query): void {
                foreach ($this->groups as $group) {
                    $query->orWhere('name', 'like', $group.'.%');
                }
            })
            ->get();

        foreach (['admin', 'Super Admin'] as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->first();

            if ($role !== null) {
                $role->givePermissionTo($adminPermissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        $names = [];
        foreach ($this->groups as $group) {
            foreach ($this->actions as $action) {
                $names[] = $group.'.'.$action;
            }
        }

        Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $names)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
