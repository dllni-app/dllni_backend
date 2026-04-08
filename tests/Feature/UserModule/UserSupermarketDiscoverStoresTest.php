<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmProductFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreHours;

it('lists supermarket stores with pagination', function (): void {
    // Arrange
    SmStore::factory()->count(3)->create([
        'is_active' => true,
    ]);

    // Act
    $response = $this->getJson('/api/v1/user/supermarket/stores');

    // Assert
    $response->assertOk()->assertJsonStructure([
        'data',
        'links',
        'meta',
    ]);
});

it('filters supermarket stores by search query param', function (): void {
    // Arrange
    SmStore::factory()->create([
        'name' => 'Super Noor Market',
        'is_active' => true,
    ]);

    SmStore::factory()->create([
        'name' => 'Another Store',
        'is_active' => true,
    ]);

    // Act
    $response = $this->getJson('/api/v1/user/supermarket/stores?search=noor');

    // Assert
    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Super Noor Market');
    expect($names)->not->toContain('Another Store');
});

it('returns distanceKm when sorting by nearest with coordinates', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect(true)->toBeTrue();

        return;
    }

    // Arrange
    SmStore::factory()->create([
        'name' => 'Near You Store',
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    // Act
    $response = $this->getJson(
        '/api/v1/user/supermarket/stores?sort=nearest&latitude=33.5200&longitude=36.2900'
    );

    // Assert
    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('name', 'Near You Store');
    expect($row)->not->toBeNull();
    expect($row['distanceKm'])->toBeFloat()->toBeGreaterThan(0);
});

it('returns distanceKm when sorting by nearestBy with coordinates', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect(true)->toBeTrue();

        return;
    }

    SmStore::factory()->create([
        'name' => 'Nearest By Store',
        'is_active' => true,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    $response = $this->getJson(
        '/api/v1/user/supermarket/stores?sort=nearestBy&latitude=33.5200&longitude=36.2900'
    );

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('name', 'Nearest By Store');
    expect($row)->not->toBeNull();
    expect($row['distanceKm'])->toBeFloat()->toBeGreaterThan(0);
});

it('sorts supermarket stores alphabetically by name', function (): void {
    SmStore::factory()->create([
        'name' => 'Zeta Store',
        'is_active' => true,
    ]);

    SmStore::factory()->create([
        'name' => 'Alpha Store',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/stores?sort=alphabet');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();

    expect(array_search('Alpha Store', $names, true))->toBeLessThan(array_search('Zeta Store', $names, true));
});

it('filters supermarket stores by openNow', function (): void {
    CarbonImmutable::setTestNow('2026-06-15 14:00:00');

    try {
        // Arrange
        $openStore = SmStore::factory()->create([
            'name' => 'Open Store',
            'is_active' => true,
            'suspension_until' => null,
        ]);

        SmStoreHours::create([
            'store_id' => $openStore->id,
            'day_of_week' => mb_strtolower(now()->englishDayOfWeek),
            'opens_at' => '08:00:00',
            'closes_at' => '22:00:00',
            'is_closed' => false,
        ]);

        $closedStore = SmStore::factory()->create([
            'name' => 'Closed Store',
            'is_active' => true,
            'suspension_until' => null,
        ]);

        SmStoreHours::create([
            'store_id' => $closedStore->id,
            'day_of_week' => mb_strtolower(now()->englishDayOfWeek),
            'opens_at' => '00:00:00',
            'closes_at' => '01:00:00',
            'is_closed' => false,
        ]);

        // Act
        $response = $this->getJson('/api/v1/user/supermarket/stores?filter[openNow]=1');

        // Assert
        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toContain('Open Store');
        expect($names)->not->toContain('Closed Store');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('includes isFavorited and highestOfferDiscountValue in store browse response', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create([
        'name' => 'Favorited Store',
        'is_active' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => SmStore::class,
        'favorable_id' => $store->id,
    ]);

    SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'discount_value' => 5,
        'ends_at' => now()->addDay(),
    ]);

    SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'discount_value' => 25,
        'ends_at' => now()->addDay(),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$user->createToken('test')->plainTextToken}")
        ->getJson('/api/v1/user/supermarket/stores');

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $store->id);

    expect($row)->not->toBeNull();
    expect($row['isFavorited'])->toBeTrue();
    expect((float) $row['highestOfferDiscountValue'])->toBe(25.0);
});

it('shows a supermarket store by id', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create([
        'name' => 'Detail Store',
        'is_active' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => SmStore::class,
        'favorable_id' => $store->id,
    ]);

    $offer = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'discount_value' => 30,
        'ends_at' => now()->addDay(),
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Detail Product',
        'is_available' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => $product->getMorphClass(),
        'favorable_id' => $product->id,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/stores/{$store->id}");

    $response->assertOk()
        ->assertJsonPath('store.id', $store->id)
        ->assertJsonPath('store.name', 'Detail Store')
        ->assertJsonPath('store.isFavorited', true)
        ->assertJsonPath('store.highestOffer.id', $offer->id)
        ->assertJsonStructure([
            'store' => [
                'owner',
                'storeHours',
                'categories',
                'products',
                'offers',
                'coupons',
                'orders',
                'documents',
                'trustLogs',
                'dailyStats',
                'commissionRules',
                'carts',
                'assistantQueries',
                'recurringOrders',
                // 'staff',
            ],
        ]);

    expect((float) $response->json('store.highestOfferDiscountValue'))->toBe(30.0);

    $productRow = collect($response->json('store.products'))->firstWhere('id', $product->id);
    expect($productRow)->not->toBeNull();
    expect($productRow['isFavorite'])->toBeTrue();
});

it('does not show inactive supermarket stores', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => false,
    ]);

    $this->getJson("/api/v1/user/supermarket/stores/{$store->id}")->assertNotFound();
});
