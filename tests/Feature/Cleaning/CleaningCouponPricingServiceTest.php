<?php

declare(strict_types=1);

use App\Models\PlatformCoupon;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningCouponPricingService;

it('keeps the worker service share unchanged while the coupon fits inside the administration margin', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('ADMIN8', 8);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    // 8% is taken from the full 1,100 customer total (= 88). Admin absorbs the
    // whole discount, so service stays 1,000 and admin drops from 100 to 12.
    expect((float) $booking->subtotal_before_discount)->toBe(1100.0)
        ->and((float) $booking->discount_amount)->toBe(88.0)
        ->and((float) $booking->admin_margin_amount)->toBe(12.0)
        ->and((float) $booking->total_price)->toBe(1012.0)
        ->and((float) $assignment->service_share_amount)->toBe(1000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(12.0)
        ->and((float) $assignment->worker_amount)->toBe(1000.0);
});

it('starts reducing service only after the full-order coupon exhausts administration margin', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('ADMIN10', 10);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(110.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(990.0)
        ->and((float) $assignment->service_share_amount)->toBe(990.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->worker_amount)->toBe(990.0);
});

it('deducts only the coupon remainder from service after administration reaches zero', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('WORKER15', 15);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);

    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    // 15% of the full 1,100 customer total is 165. Admin funds the first 100,
    // then only the remaining 65 is deducted from the service share.
    expect((float) $booking->subtotal_before_discount)->toBe(1100.0)
        ->and((float) $booking->discount_amount)->toBe(165.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(935.0)
        ->and((float) $assignment->service_share_amount)->toBe(935.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->worker_amount)->toBe(935.0);
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
        ->and($breakdown['discountAmount'])->toBe(120.0)
        ->and($breakdown['adminDiscountAmount'])->toBe(120.0)
        ->and($breakdown['platformSubsidyAmount'])->toBe(0.0)
        ->and($breakdown['workerDiscountAmount'])->toBe(0.0)
        ->and($breakdown['adminMargin'])->toBe(120.0)
        ->and($breakdown['totalPrice'])->toBe(1080.0)
        ->and((float) $assignment->service_share_amount)->toBe(960.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(120.0)
        ->and((float) $assignment->worker_amount)->toBe(960.0);
});

it('returns the corrected coupon totals through the user cleaning order api', function (): void {
    $customer = User::factory()->create();
    $coupon = cleaningPercentageCoupon('USERAPI10', 10);
    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'base_price' => 960,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 120,
        'subtotal_before_discount' => 1200,
        'discount_amount' => 120,
        'total_price' => 1080,
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
        'is_pricing_final' => true,
    ]));

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.basePrice', 960)
        ->assertJsonPath('data.servicePrice', 960)
        ->assertJsonPath('data.adminMargin', 120)
        ->assertJsonPath('data.totalPrice', 1080)
        ->assertJsonPath('data.discountAmount', 120)
        ->assertJsonPath('data.subtotalBeforeDiscount', 1200)
        ->assertJsonPath('data.bookingBasePrice', 960)
        ->assertJsonPath('data.bookingAdminMargin', 120)
        ->assertJsonPath('data.bookingTotalPrice', 1080);
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
        ->and((float) $assignment->admin_margin_amount)->toBe(12.0);

    $booking->forceFill(['total_price' => (float) $booking->total_price + 1]);
    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $assignment->service_share_amount)->toBe(1000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(12.0)
        ->and((float) $assignment->worker_amount)->toBe(1000.0);
});

it('does not reapply a coupon after the administration margin has reached zero', function (): void {
    $booking = cleaningCouponBooking();
    $worker = Worker::factory()->create();
    $assignment = cleaningCouponAssignment($booking, $worker);
    $coupon = cleaningPercentageCoupon('IDEMPOTENT10', 10);

    $booking->forceFill(['platform_coupon_id' => $coupon->id]);
    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $booking->saveQuietly();
    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->service_share_amount)->toBe(990.0)
        ->and((float) $assignment->worker_amount)->toBe(990.0);

    $booking->forceFill(['total_price' => (float) $booking->total_price + 1]);
    app(CleaningCouponPricingService::class)->applyBeforeSave($booking);
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(110.0)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->total_price)->toBe(990.0)
        ->and((float) $assignment->service_share_amount)->toBe(990.0)
        ->and((float) $assignment->worker_amount)->toBe(990.0);
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
