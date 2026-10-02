<?php

declare(strict_types=1);

use App\Models\PlatformCoupon;
use App\Models\RestaurantFinancialSetting;
use App\Models\User;
use App\Services\Coupons\PlatformCouponEligibilityService;
use App\Services\Coupons\PlatformCouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Enums\SmCommissionType;
use Modules\Supermarket\Models\SmCommissionRule;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;

uses(RefreshDatabase::class);

function fundingCoupon(array $overrides = []): PlatformCoupon
{
    return PlatformCoupon::query()->create(array_merge([
        'code' => 'FUND-'.str()->upper(str()->random(8)),
        'title_ar' => 'كوبون تمويل',
        'description_ar' => 'اختبار تمويل الكوبون',
        'section' => PlatformCoupon::SECTION_RESTAURANT,
        'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
        'discount_value' => 150,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'per_user_usage_limit' => 1,
        'is_active' => true,
        'funding_type' => PlatformCoupon::FUNDING_PLATFORM,
    ], $overrides));
}

function restaurantFinancialSetting(float $percent = 10): RestaurantFinancialSetting
{
    return RestaurantFinancialSetting::query()->create([
        'commission_type' => 'percent',
        'commission_value' => $percent,
    ]);
}
it('keeps merchant entitlement unchanged when the platform fully funds a restaurant coupon', function (): void {
    restaurantFinancialSetting(10);
    $user = User::factory()->create();
    $coupon = fundingCoupon();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'subtotal' => 1000,
        'discount_amount' => 150,
        'service_fee' => 0,
        'total_amount' => 850,
    ]);

    $redemption = app(PlatformCouponRedemptionService::class)->record(
        $coupon,
        $user->id,
        PlatformCoupon::SECTION_RESTAURANT,
        1000,
        150,
        $order,
    );

    $order->refresh();

    expect((float) $order->merchant_gross_amount)->toBe(1000.0)
        ->and((float) $order->commission_amount)->toBe(100.0)
        ->and((float) $order->merchant_net_amount)->toBe(900.0)
        ->and((float) $order->coupon_platform_funded_amount)->toBe(150.0)
        ->and((float) $order->coupon_merchant_funded_amount)->toBe(0.0)
        ->and((float) $order->platform_gross_revenue)->toBe(100.0)
        ->and((float) $order->platform_net_revenue)->toBe(-50.0)
        ->and((float) $redemption->platform_funded_amount)->toBe(150.0)
        ->and((float) $redemption->merchant_funded_amount)->toBe(0.0);
});
it('splits a shared coupon and reduces commission only by the merchant-funded share', function (): void {
    restaurantFinancialSetting(10);
    $user = User::factory()->create();
    $coupon = fundingCoupon([
        'discount_value' => 200,
        'funding_type' => PlatformCoupon::FUNDING_SHARED,
        'platform_funding_percent' => 70,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'subtotal' => 1000,
        'discount_amount' => 200,
        'service_fee' => 0,
        'total_amount' => 800,
    ]);

    app(PlatformCouponRedemptionService::class)->record(
        $coupon,
        $user->id,
        PlatformCoupon::SECTION_RESTAURANT,
        1000,
        200,
        $order,
    );

    $order->refresh();

    expect((float) $order->coupon_platform_funded_amount)->toBe(140.0)
        ->and((float) $order->coupon_merchant_funded_amount)->toBe(60.0)
        ->and((float) $order->merchant_gross_amount)->toBe(940.0)
        ->and((float) $order->commission_amount)->toBe(94.0)
        ->and((float) $order->merchant_net_amount)->toBe(846.0)
        ->and((float) $order->platform_net_revenue)->toBe(-46.0);
});

it('moves funding above the explicit platform cap to the merchant', function (): void {
    restaurantFinancialSetting(10);
    $user = User::factory()->create();
    $coupon = fundingCoupon([
        'discount_value' => 200,
        'max_platform_funding_amount' => 80,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'subtotal' => 1000,
        'discount_amount' => 200,
        'service_fee' => 0,
        'total_amount' => 800,
    ]);

    app(PlatformCouponRedemptionService::class)->record(
        $coupon,
        $user->id,
        PlatformCoupon::SECTION_RESTAURANT,
        1000,
        200,
        $order,
    );

    $order->refresh();

    expect((float) $order->coupon_platform_funded_amount)->toBe(80.0)
        ->and((float) $order->coupon_merchant_funded_amount)->toBe(120.0)
        ->and((float) $order->merchant_gross_amount)->toBe(880.0)
        ->and((float) $order->commission_amount)->toBe(88.0)
        ->and((float) $order->platform_net_revenue)->toBe(8.0);
});
it('can cap platform subsidy to platform revenue without creating a negative platform net', function (): void {
    restaurantFinancialSetting(10);
    $user = User::factory()->create();
    $coupon = fundingCoupon([
        'cap_platform_funding_to_revenue' => true,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'subtotal' => 1000,
        'discount_amount' => 150,
        'service_fee' => 0,
        'total_amount' => 850,
    ]);

    app(PlatformCouponRedemptionService::class)->record(
        $coupon,
        $user->id,
        PlatformCoupon::SECTION_RESTAURANT,
        1000,
        150,
        $order,
    );

    $order->refresh();

    expect((float) $order->coupon_platform_funded_amount)->toBeGreaterThan(94.0)
        ->and((float) $order->coupon_platform_funded_amount)->toBeLessThan(95.0)
        ->and((float) $order->coupon_merchant_funded_amount)->toBeGreaterThan(55.0)
        ->and(abs((float) $order->platform_net_revenue))->toBeLessThanOrEqual(0.02)
        ->and((bool) data_get($order->financial_snapshot, 'coupon_funding.revenue_cap_applied'))->toBeTrue();
});
it('applies the same funding model to supermarket commission snapshots', function (): void {
    $user = User::factory()->create();
    $store = SmStore::factory()->create();

    SmCommissionRule::query()->create([
        'store_id' => $store->id,
        'commission_type' => SmCommissionType::Percentage->value,
        'value' => 10,
        'is_active' => true,
        'is_default' => true,
    ]);

    $coupon = fundingCoupon([
        'section' => PlatformCoupon::SECTION_SUPERMARKET,
        'discount_value' => 200,
        'funding_type' => PlatformCoupon::FUNDING_SHARED,
        'platform_funding_percent' => 50,
    ]);

    $order = SmOrder::factory()->create([
        'customer_id' => $user->id,
        'store_id' => $store->id,
        'subtotal' => 1000,
        'discount_amount' => 200,
        'service_fee' => 0,
        'total_amount' => 800,
    ]);

    app(PlatformCouponRedemptionService::class)->record(
        $coupon,
        $user->id,
        PlatformCoupon::SECTION_SUPERMARKET,
        1000,
        200,
        $order,
    );

    $order->refresh();

    expect((float) $order->coupon_platform_funded_amount)->toBe(100.0)
        ->and((float) $order->coupon_merchant_funded_amount)->toBe(100.0)
        ->and((float) $order->merchant_gross_amount)->toBe(900.0)
        ->and((float) $order->commission_base_amount)->toBe(900.0)
        ->and((float) $order->commission_amount)->toBe(90.0)
        ->and((float) $order->store_net_amount)->toBe(810.0)
        ->and((float) $order->merchant_net_amount)->toBe(810.0)
        ->and((float) $order->platform_net_revenue)->toBe(-10.0);
});
it('reverses coupon usage on cancellation and restores user eligibility', function (): void {
    restaurantFinancialSetting(10);
    $user = User::factory()->create();
    $coupon = fundingCoupon(['per_user_usage_limit' => 1]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'subtotal' => 1000,
        'discount_amount' => 150,
        'service_fee' => 0,
        'total_amount' => 850,
    ]);

    $redemption = app(PlatformCouponRedemptionService::class)->record(
        $coupon,
        $user->id,
        PlatformCoupon::SECTION_RESTAURANT,
        1000,
        150,
        $order,
    );

    expect((int) $coupon->fresh()->used_count)->toBe(1);

    $order->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'cancellation_reason' => 'Cancelled for test',
    ]);

    $redemption->refresh();
    $order->refresh();

    $eligibility = app(PlatformCouponEligibilityService::class)->evaluate(
        $coupon->fresh(),
        $user->id,
        PlatformCoupon::SECTION_RESTAURANT,
        1000,
    );

    expect($redemption->reversed_at)->not->toBeNull()
        ->and($redemption->reversal_reason)->toBe('Cancelled for test')
        ->and((int) $coupon->fresh()->used_count)->toBe(0)
        ->and($order->financial_reversed_at)->not->toBeNull()
        ->and($eligibility['isValid'])->toBeTrue();
});


it('rechecks global usage limits inside the locked redemption transaction', function (): void {
    restaurantFinancialSetting(10);
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $coupon = fundingCoupon([
        'total_usage_limit' => 1,
        'per_user_usage_limit' => null,
    ]);

    $firstOrder = Order::factory()->create([
        'user_id' => $firstUser->id,
        'subtotal' => 1000,
        'discount_amount' => 150,
        'service_fee' => 0,
        'total_amount' => 850,
    ]);
    $secondOrder = Order::factory()->create([
        'user_id' => $secondUser->id,
        'subtotal' => 1000,
        'discount_amount' => 150,
        'service_fee' => 0,
        'total_amount' => 850,
    ]);

    $service = app(PlatformCouponRedemptionService::class);

    $service->record(
        $coupon,
        $firstUser->id,
        PlatformCoupon::SECTION_RESTAURANT,
        1000,
        150,
        $firstOrder,
    );

    expect(fn () => $service->record(
        $coupon,
        $secondUser->id,
        PlatformCoupon::SECTION_RESTAURANT,
        1000,
        150,
        $secondOrder,
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect((int) $coupon->fresh()->used_count)->toBe(1)
        ->and($coupon->redemptions()->whereNull('reversed_at')->count())->toBe(1)
        ->and($secondOrder->fresh()->platform_coupon_id)->toBeNull();
});
