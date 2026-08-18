<?php

declare(strict_types=1);

use App\Models\PlatformCoupon;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Observers\CleaningBookingObserver;
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

it('synchronizes the discounted booking and worker financial shares when a coupon is attached', function (): void {
    $coupon = PlatformCoupon::query()->create([
        'code' => 'CLEAN20',
        'title_ar' => 'خصم تنظيف',
        'description_ar' => 'خصم على كامل أجرة التنظيف',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 20,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'is_active' => true,
    ]);
    $worker = Worker::factory()->create();

    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => $worker->id,
        'base_price' => 100000,
        'addons_total' => 0,
        'travel_fee' => 5000,
        'admin_margin_amount' => 10000,
        'total_price' => 115000,
        'is_pricing_final' => true,
    ]));

    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 100000,
        'travel_fee' => 5000,
        'admin_margin_amount' => 10000,
        'worker_amount' => 95000,
        'currency' => 'SYP',
    ]);

    CleaningBookingObserver::withoutLifecycleUpdateNotificationsFor(
        (int) $booking->id,
        function () use ($booking, $coupon): void {
            $booking->forceFill([
                'platform_coupon_id' => $coupon->id,
                'platform_coupon_code' => $coupon->code,
            ])->save();
        },
    );

    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->subtotal_before_discount)->toBe(115000.0)
        ->and((float) $booking->discount_amount)->toBe(23000.0)
        ->and((float) $booking->travel_fee)->toBe(4000.0)
        ->and((float) $booking->admin_margin_amount)->toBe(8000.0)
        ->and((float) $booking->total_price)->toBe(92000.0)
        ->and((float) $assignment->service_share_amount)->toBe(80000.0)
        ->and((float) $assignment->travel_fee)->toBe(4000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(8000.0)
        ->and((float) $assignment->worker_amount)->toBe(76000.0);
});
