<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningExtendedTimePrices\CleaningExtendedTimePriceResource;
use App\Filament\Resources\CleaningExtendedTimePrices\Pages\EditCleaningExtendedTimePrice;
use App\Models\User;
use Livewire\Livewire;
use Modules\Cleaning\Models\CleaningExtendedTimePrice;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create([
        'email' => 'cleaning-extended-time-prices-admin@example.com',
    ]);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
});

it('prevents admins from creating or deleting cleaning extension time ranges', function (): void {
    $range = CleaningExtendedTimePrice::query()->firstOrFail();

    expect(CleaningExtendedTimePriceResource::canCreate())->toBeFalse()
        ->and(CleaningExtendedTimePriceResource::canDelete($range))->toBeFalse()
        ->and(CleaningExtendedTimePriceResource::canDeleteAny())->toBeFalse();
});

it('allows admins to update only the cleaning extension range price', function (): void {
    $range = CleaningExtendedTimePrice::query()
        ->where('start_minutes', 31)
        ->where('end_minutes', 45)
        ->firstOrFail();

    Livewire::test(EditCleaningExtendedTimePrice::class, [
        'record' => $range->getRouteKey(),
    ])
        ->set('data.start_minutes', 1)
        ->set('data.end_minutes', 2)
        ->set('data.price', 3456.75)
        ->call('save')
        ->assertHasNoErrors();

    $range->refresh();

    expect($range->start_minutes)->toBe(31)
        ->and($range->end_minutes)->toBe(45)
        ->and((float) $range->price)->toBe(3456.75);
});
