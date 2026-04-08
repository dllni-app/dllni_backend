<?php

declare(strict_types=1);

use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\Restaurant;

it('returns only currently active coupons for a restaurant', function (): void {
    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $activeCoupon = PromoCode::query()->create([
        'restaurant_id' => $restaurant->id,
        'code' => 'ACTIVE-' . uniqid('', true),
        'discount_type' => DiscountType::Percentage->value,
        'discount_value' => 10,
        'min_order_amount' => 100,
        'usage_limit' => 50,
        'usage_count' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $restaurant->id,
        'code' => 'INACTIVE-' . uniqid('', true),
        'discount_type' => DiscountType::Percentage->value,
        'discount_value' => 10,
        'usage_count' => 0,
        'is_active' => false,
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $restaurant->id,
        'code' => 'SCHEDULED-' . uniqid('', true),
        'discount_type' => DiscountType::FixedAmount->value,
        'discount_value' => 20,
        'usage_count' => 0,
        'starts_at' => now()->addDay(),
        'is_active' => true,
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $restaurant->id,
        'code' => 'EXPIRED-' . uniqid('', true),
        'discount_type' => DiscountType::FixedAmount->value,
        'discount_value' => 15,
        'usage_count' => 0,
        'ends_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $restaurant->id,
        'code' => 'USED-UP-' . uniqid('', true),
        'discount_type' => DiscountType::FixedAmount->value,
        'discount_value' => 15,
        'usage_limit' => 5,
        'usage_count' => 5,
        'is_active' => true,
    ]);

    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}/coupons");

    $response->assertOk()->assertJsonStructure([
        'coupons' => [
            '*' => [
                'id',
                'code',
                'discountType',
                'discountValue',
                'minOrderAmount',
                'usageLimit',
                'usageCount',
                'startsAt',
                'endsAt',
                'isActive',
            ],
        ],
    ]);

    expect($response->json('coupons'))->toHaveCount(1);
    expect($response->json('coupons.0.id'))->toBe($activeCoupon->id);
});

it('returns not found for inactive restaurant', function (): void {
    $restaurant = Restaurant::factory()->create([
        'is_active' => false,
    ]);

    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}/coupons");

    $response->assertNotFound();
});

it('returns empty coupons list when no active coupons exist', function (): void {
    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}/coupons");

    $response->assertOk();
    expect($response->json('coupons'))->toBeArray();
    expect($response->json('coupons'))->toHaveCount(0);
});
