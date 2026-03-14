<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('filters by active flag', function (): void {
    $activeStore = SmStoreFactory::new()->create(['is_active' => true]);
    SmStoreFactory::new()->create(['is_active' => false]);

    $response = $this->getJson('/api/v1/sm-stores?filter[isActive]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($activeStore->id);
});

it('filters suspended stores', function (): void {
    $suspendedStore = SmStoreFactory::new()->create([
        'suspension_until' => Carbon::now()->addDay(),
    ]);
    SmStoreFactory::new()->create(['suspension_until' => null]);

    $response = $this->getJson('/api/v1/sm-stores?filter[suspended]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($suspendedStore->id);
});

it('filters by trust score range', function (): void {
    SmStoreFactory::new()->create(['trust_score' => 10]);
    $highTrustStore = SmStoreFactory::new()->create(['trust_score' => 50]);

    $response = $this->getJson('/api/v1/sm-stores?filter[trustScoreMin]=20');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($highTrustStore->id);
});

it('filters by search term', function (): void {
    $matchedStore = SmStoreFactory::new()->create(['name' => 'Central Market']);
    SmStoreFactory::new()->create(['name' => 'Other Store']);

    $response = $this->getJson('/api/v1/sm-stores?filter[search]=Central');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($matchedStore->id);
});

it('filters by city and neighborhood', function (): void {
    $matchedStore = SmStoreFactory::new()->create([
        'city' => 'Amman',
        'neighborhood' => 'Abdoun',
    ]);

    SmStoreFactory::new()->create([
        'city' => 'Zarqa',
        'neighborhood' => 'Downtown',
    ]);

    $response = $this->getJson('/api/v1/sm-stores?filter[city]=Amman&filter[neighborhood]=Abdoun');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($matchedStore->id);
});
