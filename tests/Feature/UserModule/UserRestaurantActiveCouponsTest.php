<?php

declare(strict_types=1);

use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\Restaurant;

it('returns only currently active coupons for active restaurants', function (): void {
    $activeRestaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $inactiveRestaurant = Restaurant::factory()->create([
        'is_active' => false,
    ]);

    $activeCoupon = PromoCode::query()->create([
        'restaurant_id' => $activeRestaurant->id,
        'code' => 'ACTIVE-'.uniqid('', true),
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
        'restaurant_id' => $activeRestaurant->id,
        'code' => 'INACTIVE-'.uniqid('', true),
        'discount_type' => DiscountType::Percentage->value,
        'discount_value' => 10,
        'usage_count' => 0,
        'is_active' => false,
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $activeRestaurant->id,
        'code' => 'SCHEDULED-'.uniqid('', true),
        'discount_type' => DiscountType::FixedAmount->value,
        'discount_value' => 20,
        'usage_count' => 0,
        'starts_at' => now()->addDay(),
        'is_active' => true,
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $activeRestaurant->id,
        'code' => 'EXPIRED-'.uniqid('', true),
        'discount_type' => DiscountType::FixedAmount->value,
        'discount_value' => 15,
        'usage_count' => 0,
        'ends_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $activeRestaurant->id,
        'code' => 'USED-UP-'.uniqid('', true),
        'discount_type' => DiscountType::FixedAmount->value,
        'discount_value' => 15,
        'usage_limit' => 5,
        'usage_count' => 5,
        'is_active' => true,
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $inactiveRestaurant->id,
        'code' => 'INACTIVE-RESTAURANT-'.uniqid('', true),
        'discount_type' => DiscountType::Percentage->value,
        'discount_value' => 5,
        'usage_count' => 0,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/coupons');

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
                'restaurant' => [
                    'id',
                    'name',
                    'slug',
                    'imageUrl',
                ],
            ],
        ],
    ]);

    expect($response->json('coupons'))->toHaveCount(1);
    expect($response->json('coupons.0.id'))->toBe($activeCoupon->id);
    expect($response->json('coupons.0.restaurant.id'))->toBe($activeRestaurant->id);
});

it('returns empty coupons list when no active coupons exist', function (): void {
    Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/coupons');

    $response->assertOk();
    expect($response->json('coupons'))->toBeArray();
    expect($response->json('coupons'))->toHaveCount(0);
});
