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

    $response->assertNotFound();
});

it('does not expose the legacy sos alias', function () {
    $response = $this->postJson('/api/user/sos', [
        'order_id' => 1,
        'message' => 'I need urgent help.',
    ]);

    $response->assertNotFound();
});
