<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use App\Models\Worker;
use Illuminate\Support\Facades\Artisan;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

it('previews, repairs, and idempotently rechecks the legacy commission-inclusive order', function (): void {
    [$booking, $assignment, $redemption] = historicMinimumCouponFixture();
    $options = historicMinimumCouponOptions($booking);

    expect(Artisan::call('cleaning:repair-inclusive-coupon', $options))->toBe(0);
    expect((float) $booking->fresh()->total_price)->toBe(1957.5)
        ->and((float) $assignment->fresh()->worker_amount)->toBe(1800.0)
        ->and((float) $redemption->fresh()->subtotal)->toBe(2175.0);

    expect(Artisan::call('cleaning:repair-inclusive-coupon', [...$options, '--apply' => true]))->toBe(0);
    $booking->refresh();
    $assignment->refresh();
    $redemption->refresh();

    expect((float) $booking->subtotal_before_discount)->toBe(1800.0)
        ->and((float) $booking->discount_amount)->toBe(150.0)
        ->and((float) $booking->admin_margin_amount)->toBe(225.0)
        ->and((float) $booking->total_price)->toBe(1650.0)
        ->and((float) $assignment->service_share_amount)->toBe(1125.0)
        ->and((float) $assignment->travel_fee)->toBe(300.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(225.0)
        ->and((float) $assignment->worker_amount)->toBe(1425.0)
        ->and((float) $redemption->subtotal)->toBe(1800.0)
        ->and((float) $redemption->discount_amount)->toBe(150.0)
        ->and((float) $booking->property_details['_coupon_pricing']['grossAdminMargin'])->toBe(375.0)
        ->and((float) $booking->property_details['_coupon_pricing']['includedAdminMargin'])->toBe(375.0);

    expect(Artisan::call('cleaning:repair-inclusive-coupon', [...$options, '--apply' => true]))->toBe(0)
        ->and((float) $booking->fresh()->total_price)->toBe(1650.0)
        ->and((float) $assignment->fresh()->worker_amount)->toBe(1425.0);

    // The normal coupon observer must keep the corrected snapshot stable too.
    $booking->forceFill(['total_price' => 1651])->save();
    expect((float) $booking->fresh()->total_price)->toBe(1650.0)
        ->and((float) $assignment->fresh()->worker_amount)->toBe(1425.0);
});

it('refuses mismatched booking numbers and stale original amounts', function (): void {
    [$booking] = historicMinimumCouponFixture();
    $options = historicMinimumCouponOptions($booking);

    expect(Artisan::call('cleaning:repair-inclusive-coupon', [
        ...$options, '--booking-number' => 'NOT-THE-ORDER', '--apply' => true,
    ]))->toBe(1)
        ->and(Artisan::call('cleaning:repair-inclusive-coupon', [
            ...$options, '--expected-total' => '2100', '--apply' => true,
        ]))->toBe(1)
        ->and((float) $booking->fresh()->total_price)->toBe(1957.5);
});

it('refuses completed orders and booked worker commission ledger entries', function (): void {
    [$booking, $assignment] = historicMinimumCouponFixture();
    $options = historicMinimumCouponOptions($booking);

    $booking->forceFill(['status' => CleaningBookingStatus::Completed->value])->saveQuietly();
    expect(Artisan::call('cleaning:repair-inclusive-coupon', [...$options, '--apply' => true]))->toBe(1);

    $booking->forceFill(['status' => CleaningBookingStatus::WorkerAssigned->value])->saveQuietly();
    CleaningDepositTransaction::query()->create([
        'worker_id' => $assignment->worker_id,
        'type' => 'commission',
        'amount' => 100,
        'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX
            .hash('sha256', $assignment->worker_id.':'.$booking->id),
        'balance_before' => 1000,
        'balance_after' => 900,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
    ]);

    expect(Artisan::call('cleaning:repair-inclusive-coupon', [...$options, '--apply' => true]))->toBe(1)
        ->and((float) $booking->fresh()->total_price)->toBe(1957.5);
});

/** @return array{0:CleaningBooking,1:CleaningBookingWorkerAssignment,2:PlatformCouponRedemption} */
function historicMinimumCouponFixture(): array
{
    $worker = Worker::factory()->create();
    $coupon = PlatformCoupon::query()->create([
        'code' => 'CLEAN',
        'title_ar' => 'خصم تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'خصم على التنظيف',
        'description_en' => 'Cleaning discount',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'is_active' => true,
    ]);
    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'property_type' => 'apartment',
        'base_price' => 1500,
        'addons_total' => 0,
        'extension_fee_total' => 0,
        'travel_fee' => 300,
        'admin_margin_amount' => 157.5,
        'subtotal_before_discount' => 2175,
        'discount_amount' => 217.5,
        'total_price' => 1957.5,
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
        'service_share_amount' => 1500,
        'travel_fee' => 300,
        'admin_margin_amount' => 157.5,
        'worker_amount' => 1800,
        'currency' => 'SYP',
    ]);
    $redemption = PlatformCouponRedemption::query()->create([
        'platform_coupon_id' => $coupon->id,
        'user_id' => $booking->customer_id,
        'section' => PlatformCoupon::SECTION_CLEANING,
        'order_type' => $booking->getMorphClass(),
        'order_id' => $booking->id,
        'coupon_code' => $coupon->code,
        'subtotal' => 2175,
        'discount_amount' => 217.5,
        'redeemed_at' => now(),
    ]);

    return [$booking, $assignment, $redemption];
}

function historicMinimumCouponOptions(CleaningBooking $booking): array
{
    return [
        'booking' => $booking->id,
        '--booking-number' => $booking->booking_number,
        '--service' => '1500',
        '--travel' => '300',
        '--gross-admin' => '375',
        '--expected-total' => '1957.50',
    ];
}
