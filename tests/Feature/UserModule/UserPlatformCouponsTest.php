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
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

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
