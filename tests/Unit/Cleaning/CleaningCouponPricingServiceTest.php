<?php

declare(strict_types=1);

use App\Models\PlatformCoupon;
use Modules\Cleaning\Services\CleaningCouponPricingService;

it('applies a percentage cleaning coupon to service travel and administration amounts', function (): void {
    $coupon = new PlatformCoupon([
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 20,
    ]);

    $allocation = app(CleaningCouponPricingService::class)->allocation(
        $coupon,
        serviceAmount: 100000,
        travelFee: 5000,
        adminMargin: 10000,
    );

    expect($allocation)
        ->toMatchArray([
            'grossServiceAmount' => 100000.0,
            'grossTravelFee' => 5000.0,
            'grossAdminMargin' => 10000.0,
            'grossTotal' => 115000.0,
            'discountAmount' => 23000.0,
            'serviceAmount' => 80000.0,
            'travelFee' => 4000.0,
            'adminMargin' => 8000.0,
            'totalPrice' => 92000.0,
        ]);
});

it('allocates a fixed cleaning coupon proportionally across every price component', function (): void {
    $coupon = new PlatformCoupon([
        'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
        'discount_value' => 23000,
    ]);

    $allocation = app(CleaningCouponPricingService::class)->allocation(
        $coupon,
        serviceAmount: 100000,
        travelFee: 5000,
        adminMargin: 10000,
    );

    expect($allocation['discountAmount'])->toBe(23000.0)
        ->and($allocation['serviceAmount'])->toBe(80000.0)
        ->and($allocation['travelFee'])->toBe(4000.0)
        ->and($allocation['adminMargin'])->toBe(8000.0)
        ->and($allocation['totalPrice'])->toBe(92000.0);
});
