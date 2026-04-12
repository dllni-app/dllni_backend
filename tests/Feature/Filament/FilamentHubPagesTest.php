<?php

declare(strict_types=1);

use App\Filament\Pages\CleaningOverview;
use App\Filament\Pages\RestaurantSectionHub;
use App\Filament\Pages\SupermarketSectionHub;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create([
        'email' => 'hub-pages-test@example.com',
    ]);
    $adminUser->assignRole('admin');

    expect($adminUser->fresh()->hasRole('admin', $guardName))->toBeTrue();

    $this->actingAs($adminUser);
});

it('allows an admin to load the supermarket section hub', function (): void {
    $this->get(SupermarketSectionHub::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load the restaurant section hub', function (): void {
    $this->get(RestaurantSectionHub::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load the cleaning overview command center', function (): void {
    $this->get(CleaningOverview::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});
