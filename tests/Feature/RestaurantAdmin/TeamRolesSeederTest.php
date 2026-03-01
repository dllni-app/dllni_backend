<?php

declare(strict_types=1);

use Database\Seeders\DashboardPermissionsSeeder;
use Database\Seeders\TeamRoleTemplatesSeeder;
use Spatie\Permission\Models\Role;

it('seeds restaurant team role templates', function (): void {
    $this->seed(DashboardPermissionsSeeder::class);
    $this->seed(TeamRoleTemplatesSeeder::class);

    $roles = Role::query()->pluck('name')->all();

    expect($roles)->toContain('Super Admin');
    expect($roles)->toContain('Cleaning Ops Manager');
    expect($roles)->toContain('Customer Support');
    expect($roles)->toContain('Onboarding Specialist');
    expect($roles)->toContain('Accountant');
});
