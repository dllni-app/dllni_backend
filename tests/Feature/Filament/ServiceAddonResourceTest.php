<?php

declare(strict_types=1);

use App\Filament\Resources\ServiceAddons\Pages\CreateServiceAddon;
use App\Filament\Resources\ServiceAddons\Pages\EditServiceAddon;
use App\Models\ServiceAddon;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create([
        'email' => 'service-addon-admin@example.com',
    ]);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
    app()->setLocale('en');
});

it('rejects a negative add-on price in the Filament form', function (): void {
    $slug = 'qa-negative-'.Str::lower(Str::random(8));

    Livewire::test(CreateServiceAddon::class)
        ->fillForm([
            'name' => 'Negative price probe',
            'slug' => $slug,
            'pricing_type' => 'fixed',
            'price_value' => -1,
            'is_active' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['price_value']);

    expect(ServiceAddon::query()->where('slug', $slug)->exists())->toBeFalse();
});

it('shows required validation errors for an empty add-on form', function (): void {
    Livewire::test(CreateServiceAddon::class)
        ->fillForm([])
        ->call('create')
        ->assertHasFormErrors([
            'name',
            'slug',
            'pricing_type',
            'price_value',
        ]);
});

it('accepts zero and two-decimal add-on prices', function (float $price): void {
    $slug = 'qa-valid-'.Str::lower(Str::random(8));

    Livewire::test(CreateServiceAddon::class)
        ->fillForm([
            'name' => 'Valid price probe',
            'slug' => $slug,
            'pricing_type' => 'fixed',
            'price_value' => $price,
            'is_active' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ServiceAddon::query()->where('slug', $slug)->value('price_value'))
        ->toEqual(number_format($price, 2, '.', ''));
})->with([0.0, 12.34]);

it('rejects add-on prices with more than two decimal places in Filament', function (): void {
    Livewire::test(CreateServiceAddon::class)
        ->fillForm([
            'name' => 'Precision price probe',
            'slug' => 'precision-'.Str::lower(Str::random(8)),
            'pricing_type' => 'fixed',
            'price_value' => '12.345',
            'is_active' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['price_value']);
});

it('persists a valid add-on edit', function (): void {
    $addon = ServiceAddon::query()->create([
        'name' => 'Original add-on',
        'slug' => 'original-'.Str::lower(Str::random(8)),
        'pricing_type' => 'fixed',
        'price_value' => 10,
        'is_active' => true,
    ]);

    Livewire::test(EditServiceAddon::class, ['record' => $addon->getRouteKey()])
        ->fillForm([
            'name' => 'Updated add-on',
            'price_value' => 25.50,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($addon->refresh()->name)->toBe('Updated add-on')
        ->and((string) $addon->price_value)->toBe('25.50');
});

it('rejects a negative add-on price through the API', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/v1/service-addons', [
        'name' => 'API negative price probe',
        'slug' => 'api-negative-'.Str::lower(Str::random(8)),
        'pricingType' => 'fixed',
        'priceValue' => -1,
        'isActive' => false,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['priceValue']);
});

it('rejects add-on prices with more than two decimal places through the API', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/v1/service-addons', [
        'name' => 'API precision price probe',
        'slug' => 'api-precision-'.Str::lower(Str::random(8)),
        'pricingType' => 'fixed',
        'priceValue' => '12.345',
        'isActive' => false,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['priceValue']);
});
