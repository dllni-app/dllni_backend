<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCouponFactory;
use Database\Factories\SmOrderFactory;
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

it('returns weekly analysis for active and inactive coupons per day', function (): void {
    $store = SmStoreFactory::new()->create();
    $anotherStore = SmStoreFactory::new()->create();
    $baseDate = now()->startOfDay();

    $storeCouponOne = SmCouponFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'created_at' => $baseDate->copy()->subDays(6)->addHour(),
    ]);

    $storeCouponTwo = SmCouponFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => false,
        'created_at' => $baseDate->copy()->subDays(3)->addHours(2),
    ]);

    SmCouponFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'created_at' => $baseDate->copy()->addHours(3),
    ]);

    $anotherStoreCoupon = SmCouponFactory::new()->create([
        'store_id' => $anotherStore->id,
        'is_active' => true,
        'created_at' => $baseDate->copy()->subDays(3)->addHours(4),
    ]);

    $outOfRangeStoreCoupon = SmCouponFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => false,
        'created_at' => $baseDate->copy()->subDays(7),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'coupon_id' => $storeCouponOne->id,
        'discount_amount' => 100.00,
        'created_at' => $baseDate->copy()->subDays(5)->addHour(),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'coupon_id' => $storeCouponTwo->id,
        'discount_amount' => 23.45,
        'created_at' => $baseDate->copy()->subDays(1)->addHours(2),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $anotherStore->id,
        'coupon_id' => $anotherStoreCoupon->id,
        'discount_amount' => 999.99,
        'created_at' => $baseDate->copy()->subDays(1)->addHours(2),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'coupon_id' => $outOfRangeStoreCoupon->id,
        'discount_amount' => 50.00,
        'created_at' => $baseDate->copy()->subDays(8),
    ]);

    SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'coupon_id' => null,
        'discount_amount' => 500.00,
        'created_at' => $baseDate->copy()->subDays(1),
    ]);

    $response = $this->getJson("/api/v1/sm-coupons/weekly-analysis?storeId={$store->id}");

    $response->assertOk();

    $days = collect($response->json('data.days'))->keyBy('date');

    expect($days)->toHaveCount(7)
        ->and($days[$baseDate->copy()->subDays(6)->toDateString()]['activeCoupons'])->toBe(1)
        ->and($days[$baseDate->copy()->subDays(6)->toDateString()]['inactiveCoupons'])->toBe(0)
        ->and($days[$baseDate->copy()->subDays(3)->toDateString()]['activeCoupons'])->toBe(0)
        ->and($days[$baseDate->copy()->subDays(3)->toDateString()]['inactiveCoupons'])->toBe(1)
        ->and($days[$baseDate->toDateString()]['activeCoupons'])->toBe(1)
        ->and($days[$baseDate->toDateString()]['inactiveCoupons'])->toBe(0)
        ->and($response->json('data.totalUsedDiscountAmount'))->toBe(123.45);
});
