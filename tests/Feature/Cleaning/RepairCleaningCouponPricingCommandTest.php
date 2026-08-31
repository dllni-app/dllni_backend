<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Models\Worker;
use Illuminate\Support\Facades\Artisan;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

beforeEach(function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 0,
            'travel_distance_start_point' => 'worker_home',
        ],
    );
});

it('previews legacy coupon pricing without changing stored data', function (): void {
    [$booking, $assignment] = legacyCouponBookingForRepair('REPAIR-DRY');

    $exitCode = Artisan::call('cleaning:repair-coupon-pricing', [
        '--ids' => [$booking->id],
    ]);

    expect($exitCode)->toBe(0);

    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(120.0)
        ->and((float) $booking->admin_margin_amount)->toBe(144.0)
        ->and((float) $booking->total_price)->toBe(1080.0)
        ->and((float) $assignment->service_share_amount)->toBe(864.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(144.0)
        ->and((float) $assignment->worker_amount)->toBe(720.0);
});

it('repairs legacy coupon booking and worker assignment values', function (): void {
    [$booking, $assignment] = legacyCouponBookingForRepair('REPAIR-APPLY');

    $exitCode = Artisan::call('cleaning:repair-coupon-pricing', [
        '--apply' => true,
        '--ids' => [$booking->id],
    ]);

    expect($exitCode)->toBe(0);

    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->subtotal_before_discount)->toBe(1200.0)
        ->and((float) $booking->discount_amount)->toBe(120.0)
        ->and((float) $booking->admin_margin_amount)->toBe(120.0)
        ->and((float) $booking->total_price)->toBe(1080.0)
        ->and((float) $assignment->service_share_amount)->toBe(960.0)
        ->and((float) $assignment->travel_fee)->toBe(0.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(120.0)
        ->and((float) $assignment->worker_amount)->toBe(840.0);
});

it('repairs the intermediate service-based coupon data without reducing service again', function (): void {
    $coupon = PlatformCoupon::query()->create([
        'code' => 'REPAIR-INTERMEDIATE',
        'title_ar' => 'كوبون تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'اختبار',
        'description_en' => 'Test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
        'max_discount_amount' => null,
        'min_order_amount' => null,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'total_usage_limit' => null,
        'per_user_usage_limit' => null,
        'used_count' => 0,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
        'is_active' => true,
    ]);
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'base_price' => 960,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 144,
        'subtotal_before_discount' => 1200,
        'discount_amount' => 96,
        'total_price' => 1104,
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
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
        'admin_margin_amount' => 144,
        'worker_amount' => 816,
        'currency' => 'SYP',
    ]);

    $exitCode = Artisan::call('cleaning:repair-coupon-pricing', [
        '--apply' => true,
        '--ids' => [$booking->id],
    ]);

    expect($exitCode)->toBe(0);

    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(120.0)
        ->and((float) $booking->admin_margin_amount)->toBe(120.0)
        ->and((float) $booking->total_price)->toBe(1080.0)
        ->and((float) $assignment->service_share_amount)->toBe(960.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(120.0)
        ->and((float) $assignment->worker_amount)->toBe(840.0);
});

it('is idempotent after legacy coupon data has been repaired', function (): void {
    [$booking, $assignment] = legacyCouponBookingForRepair('REPAIR-IDEMPOTENT');

    Artisan::call('cleaning:repair-coupon-pricing', [
        '--apply' => true,
        '--ids' => [$booking->id],
    ]);

    $firstBooking = $booking->fresh();
    $firstAssignment = $assignment->fresh();

    Artisan::call('cleaning:repair-coupon-pricing', [
        '--apply' => true,
        '--ids' => [$booking->id],
    ]);

    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe((float) $firstBooking->discount_amount)
        ->and((float) $booking->admin_margin_amount)->toBe((float) $firstBooking->admin_margin_amount)
        ->and((float) $booking->total_price)->toBe((float) $firstBooking->total_price)
        ->and((float) $assignment->service_share_amount)->toBe((float) $firstAssignment->service_share_amount)
        ->and((float) $assignment->admin_margin_amount)->toBe((float) $firstAssignment->admin_margin_amount)
        ->and((float) $assignment->worker_amount)->toBe((float) $firstAssignment->worker_amount);
});

it('skips completed coupon bookings to avoid financial ledger drift', function (): void {
    [$booking, $assignment] = legacyCouponBookingForRepair(
        'REPAIR-COMPLETED',
        CleaningBookingStatus::Completed,
    );

    $exitCode = Artisan::call('cleaning:repair-coupon-pricing', [
        '--apply' => true,
        '--ids' => [$booking->id],
    ]);

    expect($exitCode)->toBe(0);

    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->discount_amount)->toBe(120.0)
        ->and((float) $booking->total_price)->toBe(1080.0)
        ->and((float) $assignment->service_share_amount)->toBe(864.0)
        ->and((float) $assignment->worker_amount)->toBe(720.0);
});

/**
 * @return array{0: CleaningBooking, 1: CleaningBookingWorkerAssignment}
 */
function legacyCouponBookingForRepair(
    string $couponCode,
    CleaningBookingStatus $status = CleaningBookingStatus::WorkerAssigned,
): array {
    $coupon = PlatformCoupon::query()->create([
        'code' => $couponCode,
        'title_ar' => 'كوبون تنظيف قديم',
        'title_en' => 'Legacy cleaning coupon',
        'description_ar' => 'اختبار إصلاح بيانات الكوبون',
        'description_en' => 'Legacy coupon repair test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
        'max_discount_amount' => null,
        'min_order_amount' => null,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'total_usage_limit' => null,
        'per_user_usage_limit' => null,
        'used_count' => 0,
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
        'is_active' => true,
    ]);

    $worker = Worker::factory()->create();

    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'status' => $status,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'base_price' => 960,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 144,
        'subtotal_before_discount' => 1200,
        'discount_amount' => 120,
        'total_price' => 1080,
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
        'is_pricing_final' => true,
    ]));

    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 864,
        'travel_fee' => 0,
        'admin_margin_amount' => 144,
        'worker_amount' => 720,
        'currency' => 'SYP',
    ]);

    return [$booking, $assignment];
}
