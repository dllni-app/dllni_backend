<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCouponFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists coupons', function (): void {
    SmCouponFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-coupons?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates a coupon', function (): void {
    $store = SmStoreFactory::new()->create();

    $payload = [
        'storeId' => $store->id,
        'code' => 'SAVE20',
        'type' => 'Percentage',
        'percent' => 20,
        'isActive' => true,
    ];

    $response = $this->postJson('/api/v1/sm-coupons', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_coupons', ['code' => 'SAVE20']);
});

it('rejects duplicate coupon codes', function (): void {
    SmCouponFactory::new()->create(['code' => 'UNIQUE']);

    $store = SmStoreFactory::new()->create();
    $payload = [
        'storeId' => $store->id,
        'code' => 'UNIQUE',
        'type' => 'Fixed',
        'value' => 10,
    ];

    $response = $this->postJson('/api/v1/sm-coupons', $payload);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['code']);
});

it('deletes a coupon', function (): void {
    $coupon = SmCouponFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-coupons/{$coupon->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_coupons', ['id' => $coupon->id]);
});
