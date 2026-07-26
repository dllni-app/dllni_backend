<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningBookings\Pages\ListCleaningBookings;
use App\Filament\Resources\SystemUsers\Pages\ListSystemUsers;
use App\Models\User;
use Livewire\Livewire;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create([
        'email' => 'dashboard-user-filters-admin@example.com',
    ]);
    $adminUser->assignRole('admin');

    $this->actingAs($adminUser);
});

it('filters system users by customer account type', function (): void {
    $customer = User::factory()->create([
        'module_type' => null,
    ]);
    $cleaningWorker = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);

    Livewire::test(ListSystemUsers::class)
        ->filterTable('account_type', 'customer')
        ->assertCanSeeTableRecords([$customer])
        ->assertCanNotSeeTableRecords([$cleaningWorker]);
});

it('filters cleaning bookings by the selected customer', function (): void {
    $selectedCustomer = User::factory()->create();
    $otherCustomer = User::factory()->create();

    $selectedCustomerBooking = CleaningBooking::factory()->create([
        'customer_id' => $selectedCustomer->id,
    ]);
    $otherCustomerBooking = CleaningBooking::factory()->create([
        'customer_id' => $otherCustomer->id,
    ]);

    Livewire::test(ListCleaningBookings::class)
        ->filterTable('customer', $selectedCustomer->id)
        ->assertCanSeeTableRecords([$selectedCustomerBooking])
        ->assertCanNotSeeTableRecords([$otherCustomerBooking]);
});
