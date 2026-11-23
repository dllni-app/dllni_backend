<?php

declare(strict_types=1);

use App\Filament\Pages\WorkerComplianceSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(filament()->getPanel('admin'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function settingsObserver(bool $authorized): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Cleaning Ops Manager', 'web'));

    if ($authorized) {
        $user->givePermissionTo(Permission::findOrCreate('cleaning.worker_compliance.configure', 'web'));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('denies compliance settings to operations staff without configure permission', function (): void {
    $this->actingAs(settingsObserver(false));

    $this->get(WorkerComplianceSettings::getUrl(isAbsolute: false))
        ->assertForbidden();
});

it('shows only non-secret read-only compliance settings to authorized staff', function (): void {
    config()->set('cleaning.worker_compliance.enabled', false);
    config()->set('cleaning.worker_compliance.proximity_radius_m', 125);
    config()->set('cleaning.worker_compliance.site_key_secret', 'secret-never-visible-in-admin');
    $this->actingAs(settingsObserver(true));

    $this->get(WorkerComplianceSettings::getUrl(isAbsolute: false))
        ->assertOk()
        ->assertSee(__('worker_compliance.settings.nav'))
        ->assertSee(__('worker_compliance.settings.radius'))
        ->assertSee('125')
        ->assertDontSee('secret-never-visible-in-admin');
});
