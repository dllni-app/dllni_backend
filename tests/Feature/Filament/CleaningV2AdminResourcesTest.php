<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningDirtinessLevels\CleaningDirtinessLevelResource;
use App\Filament\Resources\CleaningEquipmentReservations\CleaningEquipmentReservationResource;
use App\Filament\Resources\CleaningEventTypes\CleaningEventTypeResource;
use App\Filament\Resources\CleaningMaterialKits\CleaningMaterialKitResource;
use App\Filament\Resources\CleaningScheduleChangeRequests\CleaningScheduleChangeRequestResource;
use App\Filament\Resources\CleaningSpecialServiceCategories\CleaningSpecialServiceCategoryResource;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $admin = User::factory()->create(['email' => 'cleaning-v2-admin-resources@example.com']);
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('loads every cleaning v2 catalog and operations resource for administrators', function (): void {
    foreach ([
        CleaningDirtinessLevelResource::class,
        CleaningEquipmentReservationResource::class,
        CleaningEventTypeResource::class,
        CleaningMaterialKitResource::class,
        CleaningScheduleChangeRequestResource::class,
        CleaningSpecialServiceCategoryResource::class,
    ] as $resource) {
        $this->get($resource::getUrl('index', [], isAbsolute: false))
            ->assertSuccessful();
    }
});

it('keeps operational audit queues non-creatable from the dashboard', function (): void {
    expect(CleaningEquipmentReservationResource::canCreate())->toBeFalse()
        ->and(CleaningMaterialKitResource::canCreate())->toBeFalse()
        ->and(CleaningScheduleChangeRequestResource::canCreate())->toBeFalse();
});

it('allows administrators to manage the dynamic cleaning catalogs', function (): void {
    expect(CleaningDirtinessLevelResource::canViewAny())->toBeTrue()
        ->and(CleaningDirtinessLevelResource::canCreate())->toBeTrue()
        ->and(CleaningEventTypeResource::canViewAny())->toBeTrue()
        ->and(CleaningEventTypeResource::canCreate())->toBeTrue()
        ->and(CleaningSpecialServiceCategoryResource::canViewAny())->toBeTrue()
        ->and(CleaningSpecialServiceCategoryResource::canCreate())->toBeTrue();
});
