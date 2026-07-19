<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Jobs\DispatchPlatformCouponNotifications;
use App\Models\PlatformCoupon;
use App\Models\PlatformCouponConstraint;
use App\Models\PlatformCouponRedemption;
use App\Models\User;
use App\Notifications\PlatformCouponAvailableNotification;
use App\Services\Coupons\PlatformCouponEligibilityService;
use App\Services\Coupons\PlatformCouponRedemptionService;
use Database\Factories\ProductFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\CartItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

it('requires authentication to list platform coupons', function (): void {
    $this->getJson('/api/v1/user/coupons')->assertUnauthorized();
});

it('lists only active coupons available to the authenticated user and requested section', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($user);

    $allUsers = PlatformCoupon::query()->create(couponData([
        'code' => 'ALL20',
        'section' => PlatformCoupon::SECTION_ALL,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
    ]));

    $targeted = PlatformCoupon::query()->create(couponData([
        'code' => 'CLEAN10',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
    ]));
    $targeted->users()->attach($user->id);

    $otherTargeted = PlatformCoupon::query()->create(couponData([
        'code' => 'OTHER10',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
    ]));
    $otherTargeted->users()->attach($other->id);

    PlatformCoupon::query()->create(couponData([
        'code' => 'EXPIRED',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'expires_at' => now()->subMinute(),
    ]));

    PlatformCoupon::query()->create(couponData([
        'code' => 'RESTAURANT',
        'section' => PlatformCoupon::SECTION_RESTAURANT,
    ]));

    $response = $this->getJson('/api/v1/user/coupons?section=cleaning')
        ->assertOk();

    expect(collect($response->json('coupons'))->pluck('code')->all())
        ->toContain($allUsers->code, $targeted->code)
        ->not->toContain($otherTargeted->code, 'EXPIRED', 'RESTAURANT');
});

it('checks a platform restaurant coupon against the explicit cart', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create();
    $product = ProductFactory::new()->create(['restaurant_id' => $restaurant->id]);
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
    ]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 60,
        'total_price' => 120,
        'signature_hash' => 'coupon-test-item',
    ]);

    PlatformCoupon::query()->create(couponData([
        'code' => 'CART20',
        'section' => PlatformCoupon::SECTION_RESTAURANT,
        'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
        'discount_value' => 20,
        'min_order_amount' => 100,
    ]));

    $this->postJson('/api/v1/user/coupons/check', [
        'section' => 'restaurants',
        'couponCode' => 'cart20',
        'cartId' => $cart->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.section', PlatformCoupon::SECTION_RESTAURANT)
        ->assertJsonPath('data.couponCode', 'CART20')
        ->assertJsonPath('data.isAvailable', true)
        ->assertJsonPath('data.isValid', true)
        ->assertJsonPath('data.amounts.subtotal', 120)
        ->assertJsonPath('data.amounts.discount', 20)
        ->assertJsonPath('data.amounts.total', 100)
        ->assertJsonPath('data.coupon.source', 'platform');
});

it('enforces cleaning property mode and event constraints', function (): void {
    $user = User::factory()->create();
    $coupon = PlatformCoupon::query()->create(couponData([
        'code' => 'DEEPVILLA',
        'section' => PlatformCoupon::SECTION_CLEANING,
    ]));

    $coupon->constraints()->createMany([
        ['constraint_type' => PlatformCouponConstraint::TYPE_PROPERTY, 'constraint_value' => 'villa'],
        ['constraint_type' => PlatformCouponConstraint::TYPE_CLEANING_MODE, 'constraint_value' => 'deep'],
    ]);

    $service = app(PlatformCouponEligibilityService::class);

    expect($service->evaluate($coupon, $user->id, 'cleaning', 1000, [
        'propertyType' => 'villa',
        'cleaningMode' => 'deep',
    ]))
        ->toMatchArray(['isValid' => true, 'reason' => 'ok'])
        ->and($service->evaluate($coupon, $user->id, 'cleaning', 1000, [
            'propertyType' => 'apartment',
            'cleaningMode' => 'deep',
        ]))
        ->toMatchArray(['isValid' => false, 'reason' => 'property_type_not_supported']);
});

it('enforces per-user usage limit and percentage maximum discount', function (): void {
    $user = User::factory()->create();
    $coupon = PlatformCoupon::query()->create(couponData([
        'code' => 'CAPPED50',
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 50,
        'max_discount_amount' => 100,
        'per_user_usage_limit' => 1,
    ]));

    $service = app(PlatformCouponEligibilityService::class);
    expect($service->calculateDiscount($coupon, 1000))->toBe(100.0);

    PlatformCouponRedemption::query()->create([
        'platform_coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'section' => PlatformCoupon::SECTION_RESTAURANT,
        'order_type' => 'test-order',
        'order_id' => 1,
        'coupon_code' => $coupon->code,
        'subtotal' => 1000,
        'discount_amount' => 100,
        'redeemed_at' => now(),
    ]);

    expect($service->evaluate($coupon->fresh(), $user->id, PlatformCoupon::SECTION_RESTAURANT, 1000))
        ->toMatchArray(['isValid' => false, 'reason' => 'user_usage_limit_reached']);
});

it('records redemption usage and order coupon snapshots in one transaction', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'subtotal' => 200,
        'discount_amount' => 30,
        'total_amount' => 170,
    ]);
    $coupon = PlatformCoupon::query()->create(couponData([
        'code' => 'ORDER30',
        'section' => PlatformCoupon::SECTION_RESTAURANT,
        'discount_value' => 30,
        'total_usage_limit' => 2,
        'per_user_usage_limit' => 1,
    ]));
    $service = app(PlatformCouponRedemptionService::class);

    DB::transaction(function () use ($service, $coupon, $user, $order): void {
        $quote = $service->quoteForPlacement(
            userId: $user->id,
            section: PlatformCoupon::SECTION_RESTAURANT,
            couponCode: $coupon->code,
            subtotal: 200,
            required: true,
        );

        $service->record(
            coupon: $quote['coupon'],
            userId: $user->id,
            section: PlatformCoupon::SECTION_RESTAURANT,
            subtotal: 200,
            discount: (float) $quote['discount'],
            order: $order,
        );
    });

    $this->assertDatabaseHas('platform_coupon_redemptions', [
        'platform_coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'order_id' => $order->id,
        'coupon_code' => 'ORDER30',
        'discount_amount' => 30,
    ]);

    expect($coupon->fresh()->used_count)->toBe(1)
        ->and($order->fresh()->platform_coupon_id)->toBe($coupon->id)
        ->and($order->fresh()->platform_coupon_code)->toBe('ORDER30');
});

it('dispatches coupon notifications only to selected users', function (): void {
    Notification::fake();

    $selected = User::factory()->create(['is_active' => true]);
    $other = User::factory()->create(['is_active' => true]);
    $coupon = PlatformCoupon::query()->create(couponData([
        'code' => 'PRIVATE10',
        'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
    ]));
    $coupon->users()->attach($selected->id);

    (new DispatchPlatformCouponNotifications($coupon->id))->handle();

    Notification::assertSentTo($selected, PlatformCouponAvailableNotification::class);
    Notification::assertNotSentTo($other, PlatformCouponAvailableNotification::class);
    expect($coupon->fresh()->notification_sent_at)->not->toBeNull();
});

it('dispatches all-user coupon notifications only to active customer accounts', function (): void {
    Notification::fake();

    $customer = User::factory()->create(['is_active' => true, 'module_type' => null]);
    $inactiveCustomer = User::factory()->create(['is_active' => false, 'module_type' => null]);
    $worker = User::factory()->create([
        'is_active' => true,
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $coupon = PlatformCoupon::query()->create(couponData([
        'code' => 'CUSTOMERS10',
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
    ]));

    (new DispatchPlatformCouponNotifications($coupon->id))->handle();

    Notification::assertSentTo($customer, PlatformCouponAvailableNotification::class);
    Notification::assertNotSentTo($inactiveCustomer, PlatformCouponAvailableNotification::class);
    Notification::assertNotSentTo($worker, PlatformCouponAvailableNotification::class);
});

/** @return array<string, mixed> */
function couponData(array $overrides = []): array
{
    return array_replace([
        'code' => 'SAVE10',
        'title_ar' => 'خصم جديد',
        'title_en' => 'New discount',
        'description_ar' => 'خصم متاح للمستخدم',
        'description_en' => 'A discount for the user',
        'section' => PlatformCoupon::SECTION_ALL,
        'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
        'discount_value' => 10,
        'max_discount_amount' => null,
        'min_order_amount' => null,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'total_usage_limit' => null,
        'per_user_usage_limit' => 1,
        'used_count' => 0,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
        'is_active' => true,
    ], $overrides);
}
