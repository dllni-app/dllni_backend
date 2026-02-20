<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists disputes', function () {
    $response = $this->getJson('/api/v1/disputes');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists system alerts', function () {
    $response = $this->getJson('/api/v1/system-alerts');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists sos alerts', function () {
    $response = $this->getJson('/api/v1/sos-alerts');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});
