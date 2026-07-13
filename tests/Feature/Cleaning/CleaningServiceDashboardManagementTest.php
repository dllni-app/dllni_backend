<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningServices\CleaningServiceResource as DashboardCleaningServiceResource;
use App\Filament\Resources\ServiceAddons\ServiceAddonResource;
use Modules\Cleaning\Enums\ServiceCategory;
use Modules\Cleaning\Models\CleaningService;

it('shows cleaning services management and hides the legacy service add-ons navigation', function (): void {
    expect(DashboardCleaningServiceResource::shouldRegisterNavigation())->toBeTrue()
        ->and(ServiceAddonResource::shouldRegisterNavigation())->toBeFalse()
        ->and(DashboardCleaningServiceResource::getModel())->toBe(CleaningService::class);
});

it('uses dashboard-managed cleaning service records in the customer API', function (): void {
    $service = CleaningService::query()->create([
        'name' => 'تنظيف الزجاج',
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'تنظيف الزجاج والنوافذ.',
        'price' => 25,
        'is_active' => true,
    ]);

    expect($service->slug)->toBe('service');

    $this->getJson('/api/v1/cleaning-services?filter[isActive]=1')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $service->id,
            'name' => 'تنظيف الزجاج',
            'isActive' => true,
        ]);
});

it('keeps generated cleaning service slugs unique for direct dashboard CRUD', function (): void {
    $first = CleaningService::query()->create([
        'name' => 'Window Cleaning',
        'category' => ServiceCategory::Cleaning->value,
        'price' => 20,
        'is_active' => true,
    ]);

    $second = CleaningService::query()->create([
        'name' => 'Window Cleaning',
        'category' => ServiceCategory::Cleaning->value,
        'price' => 30,
        'is_active' => true,
    ]);

    expect($first->slug)->toBe('window-cleaning')
        ->and($second->slug)->toBe('window-cleaning-2');

    $second->update(['name' => 'Deep Window Cleaning']);

    expect($second->refresh()->slug)->toBe('deep-window-cleaning');
});
