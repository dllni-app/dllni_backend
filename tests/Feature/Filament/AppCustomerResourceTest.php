<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Resources\AppCustomers\Pages\EditAppCustomer;
use App\Filament\Resources\AppCustomers\Pages\ListAppCustomers;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create(['email' => 'app-customers-admin@example.com']);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
});

it('lists only end-user app customers with null module_type', function (): void {
    $customer = User::factory()->create([
        'name' => 'App Customer',
        'email' => 'customer@example.com',
        'module_type' => null,
    ]);
    $worker = User::factory()->create([
        'name' => 'Worker User',
        'email' => 'worker@example.com',
        'module_type' => UserModuleType::CleaningWorker,
    ]);

    Livewire::test(ListAppCustomers::class)
        ->assertCanSeeTableRecords([$customer])
        ->assertCanNotSeeTableRecords([$worker]);
});

it('updates an app customer name email and phone', function (): void {
    $customer = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
        'phone' => '+963911111111',
        'module_type' => null,
    ]);

    Livewire::test(EditAppCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm([
            'name' => 'New Name',
            'email' => 'new@example.com',
            'phone' => '+963922222222',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $customer->refresh();

    expect($customer->name)->toBe('New Name')
        ->and($customer->email)->toBe('new@example.com')
        ->and($customer->phone)->toBe('+963922222222')
        ->and($customer->module_type)->toBeNull();
});
