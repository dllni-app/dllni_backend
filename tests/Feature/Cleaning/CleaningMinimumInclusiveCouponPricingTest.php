<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningCouponPricingService;

it('does not add commission again when coupon applies to a minimum-inclusive cleaning order', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'cleaning_minimum_order_price' => 1500,
        ],
    );

    $coupon = PlatformCoupon::query()->create([
        'code' => 'MINIMUM10',
        'title_en' => 'Minimum cleaning discount',
        'description_ar' => 'Regression test',
        'description_en' => 'Minimum price regression',
        'title_ar' => 'خصم الحد الأدنى',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'is_active' => true,
    ]);

    $worker = Worker::factory()->create();
    // Simulate the checkout's canonical undiscounted price, where the 375
    // administration margin is already included in the 1,500 service charge.
    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => $worker->id,
        'base_price' => 1500,
        'addons_total' => 0,
        'travel_fee' => 300,
        'admin_margin_amount' => 375,
        'subtotal_before_discount' => 1800,
        'discount_amount' => 150,
        'total_price' => 1650,
        'is_pricing_final' => true,
    ]));
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 1500,
        'travel_fee' => 300,
        'admin_margin_amount' => 375,
        'worker_amount' => 1425,
        'currency' => 'SYP',
    ]);

    $booking->forceFill([
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
    ])->save();

    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->subtotal_before_discount)->toBe(1800.0)
        ->and((float) $booking->discount_amount)->toBe(150.0)
        ->and((float) $booking->total_price)->toBe(1650.0)
        ->and((float) $booking->admin_margin_amount)->toBe(225.0)
        ->and((float) $assignment->service_share_amount)->toBe(1125.0)
        ->and((float) $assignment->travel_fee)->toBe(300.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(225.0)
        ->and((float) $assignment->worker_amount)->toBe(1425.0);

    $breakdown = app(CleaningCouponPricingService::class)->storedBreakdown($booking);
    expect($breakdown)->not->toBeNull()
        ->and($breakdown['includedAdminMargin'])->toBe(375.0)
        ->and($breakdown['grossTotal'])->toBe(1800.0)
        ->and($breakdown['discountableSubtotal'])->toBe(1500.0)
        ->and($breakdown['totalPrice'])->toBe(1650.0);

    // A later update must not remove the included margin or reapply the discount.
    $booking->forceFill(['total_price' => 1651])->save();
    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->total_price)->toBe(1650.0)
        ->and((float) $booking->discount_amount)->toBe(150.0)
        ->and((float) $assignment->worker_amount)->toBe(1425.0);
});

it('never discounts the travel allowance even for an oversized fixed coupon', function (): void {
    $coupon = new PlatformCoupon([
        'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
        'discount_value' => 5000,
    ]);

    $allocation = app(CleaningCouponPricingService::class)->allocation(
        $coupon,
        serviceAmount: 1500,
        travelFee: 300,
        adminMargin: 375,
        includedAdminMargin: 375,
    );

    expect($allocation['discountAmount'])->toBe(1500.0)
        ->and($allocation['adminMargin'])->toBe(0.0)
        ->and($allocation['serviceAmount'])->toBe(0.0)
        ->and($allocation['travelFee'])->toBe(300.0)
        ->and($allocation['totalPrice'])->toBe(300.0);
});
