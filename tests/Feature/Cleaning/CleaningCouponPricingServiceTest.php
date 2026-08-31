<?php

declare(strict_types=1);

use App\Models\PlatformCoupon;
use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningCouponPricingService;

it('uses the administration percentage before reducing worker earnings', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('ADMIN8', 8);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    // 8% is taken from the 1,000 service amount. The admin percentage drops
    // from 10% to 2%, while the workers' service share remains untouched.
    expect((float) $booking->subtotal_before_discount)->toBe(1100.0)
        ->and((float) $booking->discount_amount)->toBe(80.0)
        ->and((float) $booking->admin_margin_amount)->toBe(20.0)
        ->and((float) $booking->total_price)->toBe(1020.0)
        ->and((float) $assignment->service_share_amount)->toBe(1000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(20.0)
        ->and((float) $assignment->worker_amount)->toBe(980.0);
});

it('does not reduce worker earnings when coupon percentage equals administration percentage', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('ADMIN10', 10);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(100.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(1000.0)
        ->and((float) $assignment->service_share_amount)->toBe(1000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->worker_amount)->toBe(1000.0);
});

it('reduces worker earnings only by percentage points above the administration percentage', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('WORKER15', 15);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    // Admin is 10% of 1,000. A 15% coupon consumes those 10 points first;
    // only the extra 5% (50) is then deducted from the workers' service share.
    expect((float) $booking->subtotal_before_discount)->toBe(1100.0)
        ->and((float) $booking->discount_amount)->toBe(150.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(950.0)
        ->and((float) $assignment->service_share_amount)->toBe(950.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->worker_amount)->toBe(950.0);
});

it('returns a persisted dashboard breakdown that explains the customer and worker totals', function (): void {
    $coupon = cleaningPercentageCoupon('DASH10', 10);
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'base_price' => 960,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 240,
        'total_price' => 1200,
        'is_pricing_final' => true,
    ]));
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 960,
        'travel_fee' => 0,
        'admin_margin_amount' => 240,
        'worker_amount' => 720,
        'currency' => 'SYP',
    ]);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);
    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $booking->saveQuietly();
    $booking->refresh();
    $assignment->refresh();

    $breakdown = app(CleaningCouponPricingService::class)->storedBreakdown($booking);

    expect($breakdown)->not->toBeNull()
        ->and($breakdown['grossServiceAmount'])->toBe(960.0)
        ->and($breakdown['grossAdminMargin'])->toBe(240.0)
        ->and($breakdown['grossTotal'])->toBe(1200.0)
        ->and($breakdown['discountAmount'])->toBe(96.0)
        ->and($breakdown['adminDiscountAmount'])->toBe(96.0)
        ->and($breakdown['platformSubsidyAmount'])->toBe(0.0)
        ->and($breakdown['workerDiscountAmount'])->toBe(0.0)
        ->and($breakdown['adminMargin'])->toBe(144.0)
        ->and($breakdown['totalPrice'])->toBe(1104.0)
        ->and((float) $assignment->service_share_amount)->toBe(960.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(144.0)
        ->and((float) $assignment->worker_amount)->toBe(816.0);
});

it('does not reapply the coupon to worker shares on a later pricing save', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('IDEMPOTENT8', 8);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);
    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $booking->saveQuietly();

    $booking->refresh();
    $assignment->refresh();

    expect((float) $assignment->service_share_amount)->toBe(1000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(20.0);

    $booking->forceFill(['total_price' => (float) $booking->total_price]);
    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $assignment->service_share_amount)->toBe(1000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(20.0)
        ->and((float) $assignment->worker_amount)->toBe(980.0);
});

it('keeps fixed coupons amount-based with administration first', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningFixedCoupon('FIXED150', 150);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(150.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(950.0)
        ->and((float) $assignment->service_share_amount)->toBe(950.0)
        ->and((float) $assignment->worker_amount)->toBe(950.0);
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

function cleaningPercentageCoupon(string $code, float $discount): PlatformCoupon
{
    return cleaningCoupon($code, PlatformCoupon::DISCOUNT_PERCENTAGE, $discount);
}

function cleaningFixedCoupon(string $code, float $discount): PlatformCoupon
{
    return cleaningCoupon($code, PlatformCoupon::DISCOUNT_FIXED, $discount);
}

function cleaningCoupon(string $code, string $type, float $discount): PlatformCoupon
{
    return PlatformCoupon::query()->create([
        'code' => $code,
        'title_ar' => 'كوبون تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'اختبار توزيع خصم الكوبون',
        'description_en' => 'Coupon allocation test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => $type,
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
