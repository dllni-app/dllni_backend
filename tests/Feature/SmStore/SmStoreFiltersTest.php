<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmStore;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('filters by active flag', function (): void {
    $activeStore = SmStore::factory()->create(['is_active' => true]);
    SmStore::factory()->create(['is_active' => false]);

    $response = $this->getJson('/api/v1/sm-stores?filter[isActive]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($activeStore->id);
});

it('filters suspended stores', function (): void {
    $suspendedStore = SmStore::factory()->create([
        'suspension_until' => Carbon::now()->addDay(),
    ]);
    SmStore::factory()->create(['suspension_until' => null]);

    $response = $this->getJson('/api/v1/sm-stores?filter[suspended]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($suspendedStore->id);
});

it('filters by trust score range', function (): void {
    SmStore::factory()->create(['trust_score' => 10]);
    $highTrustStore = SmStore::factory()->create(['trust_score' => 50]);

    $response = $this->getJson('/api/v1/sm-stores?filter[trustScoreMin]=20');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($highTrustStore->id);
});

it('filters by search term', function (): void {
    $matchedStore = SmStore::factory()->create(['name' => 'Central Market']);
    SmStore::factory()->create(['name' => 'Other Store']);

    $response = $this->getJson('/api/v1/sm-stores?filter[search]=Central');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($matchedStore->id);
});
