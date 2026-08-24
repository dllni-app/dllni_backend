<?php

declare(strict_types=1);

use App\Models\PlatformCoupon;
use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningCouponPricingService;

it('uses the administration margin before reducing worker earnings', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningFixedCoupon('ADMIN80', 80);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $booking->subtotal_before_discount)->toBe(1100.0)
        ->and((float) $booking->discount_amount)->toBe(80.0)
        ->and((float) $booking->admin_margin_amount)->toBe(20.0)
        ->and((float) $booking->total_price)->toBe(1020.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(20.0)
        ->and((float) $assignment->worker_amount)->toBe(900.0);
});

it('reduces worker earnings only by the coupon amount above the administration margin', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningFixedCoupon('WORKER150', 150);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $booking->subtotal_before_discount)->toBe(1100.0)
        ->and((float) $booking->discount_amount)->toBe(150.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(950.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->worker_amount)->toBe(850.0);
});

function cleaningCouponBooking(): CleaningBooking
{
    return CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'base_price' => 1000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 100,
        'total_price' => 1100,
        'is_pricing_final' => true,
    ]));
}

function cleaningCouponAssignment(CleaningBooking $booking, Worker $worker): CleaningBookingWorkerAssignment
{
    return CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 1000,
        'travel_fee' => 0,
        'admin_margin_amount' => 100,
        'worker_amount' => 900,
        'currency' => 'SYP',
    ]);
}

function cleaningFixedCoupon(string $code, float $discount): PlatformCoupon
{
    return PlatformCoupon::query()->create([
        'code' => $code,
        'title_ar' => 'كوبون تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'اختبار توزيع خصم الكوبون',
        'description_en' => 'Coupon allocation test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
        'discount_value' => $discount,
        'max_discount_amount' => null,
        'min_order_amount' => null,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'total_usage_limit' => null,
        'per_user_usage_limit' => null,
        'used_count' => 0,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
        'is_active' => true,
    ]);
}
