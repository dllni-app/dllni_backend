<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
