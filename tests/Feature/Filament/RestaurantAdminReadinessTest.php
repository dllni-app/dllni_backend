<?php

declare(strict_types=1);

use App\Enums\RestaurantAdminReadinessFilter;
use App\Filament\Resources\Restaurants\RestaurantResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Resturants\Models\Restaurant;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);
    $adminUser = User::factory()->create([
        'email' => 'restaurant-readiness@example.com',
    ]);
    $adminUser->assignRole('admin');

    $this->actingAs($adminUser, 'web');
});

it('counts restaurants missing cuisine types using the shared admin scope', function (): void {
    Restaurant::factory()->count(2)->create();

    expect(Restaurant::query()->adminMissingCuisineTypes()->count())->toBe(2);
});

it('allows an admin to open the restaurant list with a readiness filter in the query string', function (): void {
    Restaurant::factory()->create();

    $query = http_build_query([
        'filters' => [
            'readiness' => ['value' => RestaurantAdminReadinessFilter::MissingCuisineTypes->value],
        ],
    ]);

    $this->get(RestaurantResource::getUrl('index').'?'.$query)
        ->assertSuccessful();
});
