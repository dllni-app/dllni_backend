<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningBookingAdminPricingService;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\Cleaning\Services\CleaningTimeWarningService;
use Modules\User\Services\UserCleaningOrderEstimationService;

it('allows an admin to change room quantities with a server-side price and duration recalculation', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'booking_kind' => 'standard',
        'property_type' => 'apartment',
        'address_latitude' => 36.20,
        'address_longitude' => 37.15,
        'property_details' => [
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
            ],
        ],
    ]);

    $updated = app(CleaningBookingAdminPricingService::class)
        ->setRoomQuantity($booking, 'bedroom', 'small', 2);

    expect((int) data_get($updated->property_details, 'room_size_breakdown.bedroom.small'))->toBe(2)
        ->and($updated->rooms()->where('room_type', 'bedroom')->count())->toBe(2)
        ->and((float) $updated->base_price)->toBeGreaterThan(0)
        ->and((float) $updated->estimated_hours)->toBeGreaterThan(0)
        ->and($updated->status)->toBe(CleaningBookingStatus::Pending);
});

it('blocks administrative price edits after worker travel has started', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'started_travel_at' => now(),
        'booking_kind' => 'standard',
        'property_type' => 'apartment',
    ]);

    expect(fn () => app(CleaningBookingAdminPricingService::class)->setBasePrice($booking, 5000))
        ->toThrow(InvalidArgumentException::class);
});

it('preserves per-worker transport allowance across admin room and base-price edits', function (int $workerCount): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 0,
        'commission_type' => 'percent',
        'commission_fixed_amount' => null,
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 75,
        'travel_per_km' => 10,
        'travel_distance_start_point' => 'worker_home',
    ]);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'number_of_workers' => $workerCount,
        'booking_kind' => 'standard',
        'property_type' => 'apartment',
        'address_latitude' => 36.20,
        'address_longitude' => 37.15,
        'property_details' => [
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
            ],
        ],
    ]);

    $updated = app(CleaningBookingAdminPricingService::class)
        ->setRoomQuantity($booking, 'bedroom', 'small', 2);

    $quoted = app(UserCleaningOrderEstimationService::class)->price(
        'apartment',
        $updated->property_details,
        $updated->address_latitude,
        $updated->address_longitude,
        null,
        null,
        $workerCount,
    );

    expect((float) $quoted['travelFee'])->toBe(75.0 * $workerCount)
        ->and((float) $updated->travel_fee)->toBe(75.0 * $workerCount)
        ->and((float) $updated->total_price)->toBe((float) $quoted['totalPrice'])
        ->and((int) $updated->number_of_workers)->toBe($workerCount)
        ->and($updated->is_pricing_final)->toBeFalse()
        ->and($updated->status)->toBe(CleaningBookingStatus::Pending);

    $edited = app(CleaningBookingAdminPricingService::class)->setBasePrice($updated, 5000);
    expect((float) $edited->base_price)->toBe(5000.0)
        ->and((float) $edited->travel_fee)->toBe(75.0 * $workerCount)
        ->and((float) $edited->total_price)->toBe(5000.0 + 75.0 * $workerCount)
        ->and((int) $edited->number_of_workers)->toBe($workerCount)
        ->and($edited->is_pricing_final)->toBeFalse();
})->with([2, 3]);

it('preserves an approved extension charge when a coupon booking accepts and repeats extension pricing', function (): void {
    $coupon = PlatformCoupon::query()->create([
        'code' => 'EXTENSION-'.uniqid(),
        'title_ar' => 'كوبون تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'اختبار تمديد مع كوبون',
        'description_en' => 'Coupon extension pricing regression',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
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
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'base_price' => 1000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 1000,
        'extension_fee_total' => 0,
        'subtotal_before_discount' => 1000,
    ]);
    $booking->forceFill([
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
    ])->save();
    $booking->refresh();
    $initialCustomerTotal = 1000.0 - 100.0;

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'customer_response' => 'extend_time',
        'customer_responded_at' => now(),
        'worker_response' => null,
        'sent_at' => now(),
        'additional_minutes' => 30,
        'quoted_base_amount' => 50,
        'quoted_admin_margin_amount' => 0,
        'quoted_amount' => 50,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
    ]);

    app(CleaningTimeWarningService::class)->accept($warning);
    $booking->refresh();
    expect((float) $booking->extension_fee_total)->toBe(50.0)
        ->and((float) $booking->discount_amount)->toBe(100.0)
        ->and((float) $booking->total_price)->toBe($initialCustomerTotal + 50.0);

    $booking->forceFill(['total_price' => (float) $booking->total_price + 1])->save();
    app(CleaningTimeWarningService::class)->accept($warning->fresh());
    $booking->refresh();
    expect((float) $booking->extension_fee_total)->toBe(50.0)
        ->and((float) $booking->discount_amount)->toBe(100.0)
        ->and((float) $booking->total_price)->toBe($initialCustomerTotal + 50.0);
});

it('preserves commission-funded coupon allocation and approved extension shares across later saves', function (
    string $discountType,
    float $discountValue,
    float $expectedDiscount,
    float $expectedServiceShare,
    float $expectedAdminMargin,
    float $expectedOriginalTotal,
): void {
    $coupon = PlatformCoupon::query()->create([
        'code' => 'EXT-COMMISSION-'.uniqid(),
        'title_ar' => 'كوبون تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'اختبار تمديد بعمولة',
        'description_en' => 'Commissioned coupon extension regression',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
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
    $customer = User::factory()->create();
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::InProgress,
        'base_price' => 1000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 200,
        'total_price' => 1200,
        'subtotal_before_discount' => 1200,
        'discount_amount' => 0,
        'extension_fee_total' => 0,
    ]);
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'in_progress',
        'service_share_amount' => 1000,
        'travel_fee' => 0,
        'admin_margin_amount' => 200,
        'worker_amount' => 1000,
        'currency' => 'SYP',
    ]);
    $redemption = PlatformCouponRedemption::query()->create([
        'platform_coupon_id' => $coupon->id,
        'user_id' => $customer->id,
        'section' => PlatformCoupon::SECTION_CLEANING,
        'order_type' => $booking->getMorphClass(),
        'order_id' => $booking->id,
        'coupon_code' => $coupon->code,
        'subtotal' => 1200,
        'discount_amount' => 0,
    ]);

    $booking->forceFill([
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
    ])->save();
    $booking->refresh();
    $assignment->refresh();
    $redemption->refresh();
    $couponSnapshot = data_get($booking->property_details, '_coupon_pricing');

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $worker->id,
        'customer_response' => 'extend_time',
        'customer_responded_at' => now(),
        'worker_response' => null,
        'sent_at' => now(),
        'additional_minutes' => 30,
        'quoted_base_amount' => 50,
        'quoted_admin_margin_amount' => 5,
        'quoted_amount' => 55,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
    ]);

    app(CleaningTimeWarningService::class)->accept($warning);
    $booking->refresh();
    $assignment->refresh();
    $redemption->refresh();

    expect((float) $booking->extension_fee_total)->toBe(55.0)
        ->and((float) $booking->discount_amount)->toBe($expectedDiscount)
        ->and((float) $booking->subtotal_before_discount)->toBe(1200.0)
        ->and((float) $booking->admin_margin_amount)->toBe($expectedAdminMargin + 5.0)
        ->and((float) $booking->total_price)->toBe($expectedOriginalTotal + 55.0)
        ->and(data_get($booking->property_details, '_coupon_pricing'))->toBe($couponSnapshot)
        ->and((float) $redemption->subtotal)->toBe(1200.0)
        ->and((float) $redemption->discount_amount)->toBe($expectedDiscount)
        ->and((float) $assignment->service_share_amount)->toBe($expectedServiceShare + 55.0)
        ->and((float) $assignment->admin_margin_amount)->toBe($expectedAdminMargin + 5.0)
        ->and((float) $assignment->worker_amount)->toBe($expectedServiceShare + 55.0);

    $booking->forceFill(['total_price' => (float) $booking->total_price + 1])->save();
    app(CleaningTimeWarningService::class)->accept($warning->fresh());
    $booking->refresh();
    $assignment->refresh();
    expect((float) $booking->extension_fee_total)->toBe(55.0)
        ->and((float) $booking->discount_amount)->toBe($expectedDiscount)
        ->and((float) $booking->admin_margin_amount)->toBe($expectedAdminMargin + 5.0)
        ->and((float) $booking->total_price)->toBe($expectedOriginalTotal + 55.0)
        ->and((float) $assignment->service_share_amount)->toBe($expectedServiceShare + 55.0)
        ->and((float) $assignment->admin_margin_amount)->toBe($expectedAdminMargin + 5.0)
        ->and((float) $assignment->worker_amount)->toBe($expectedServiceShare + 55.0);
})->with([
    'discount stays in original administration' => [
        PlatformCoupon::DISCOUNT_PERCENTAGE,
        10.0,
        120.0,
        1000.0,
        80.0,
        1080.0,
    ],
    'discount exhausts original administration and reaches worker service' => [
        PlatformCoupon::DISCOUNT_FIXED,
        250.0,
        250.0,
        950.0,
        0.0,
        950.0,
    ],
]);

it('keeps finalized multi-worker coupon shares stable after a full-team recalculation', function (int $workerCount): void {
    $coupon = PlatformCoupon::query()->create([
        'code' => 'FINAL-TEAM-'.uniqid(),
        'title_ar' => 'كوبون تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'اختبار توزيع كوبون لفريق مكتمل',
        'description_en' => 'Finalized multi-worker coupon allocation regression',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
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
    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::Pending,
        'worker_id' => null,
        'number_of_workers' => $workerCount,
        'booking_kind' => 'standard',
        'property_type' => 'apartment',
        'address_latitude' => null,
        'address_longitude' => null,
        'base_price' => 3000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 3000,
        'subtotal_before_discount' => 3000,
        'discount_amount' => 0,
        'property_details' => [
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 2, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
            ],
        ],
    ]);
    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking);
    for ($slot = 0; $slot < $workerCount; $slot++) {
        $worker = Worker::factory()->create([
            'home_address' => 'Team location '.$slot,
            'home_latitude' => 36.20,
            'home_longitude' => 37.15,
        ]);
        CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => 'accepted_waiting_for_order_start',
            'accepted_at' => now()->addSeconds($slot),
            'room_count' => 0,
            'rooms_weight' => 0,
            'service_share_amount' => 0,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'worker_amount' => 0,
            'currency' => 'SYP',
        ]);
    }
    $redemption = PlatformCouponRedemption::query()->create([
        'platform_coupon_id' => $coupon->id,
        'user_id' => $customer->id,
        'section' => PlatformCoupon::SECTION_CLEANING,
        'order_type' => $booking->getMorphClass(),
        'order_id' => $booking->id,
        'coupon_code' => $coupon->code,
        'subtotal' => 3000,
        'discount_amount' => 0,
    ]);
    $booking->forceFill([
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
    ])->save();

    $finalized = $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: true);
    $assignments = CleaningBookingWorkerAssignment::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('accepted_at')
        ->orderBy('id')
        ->get();
    $rooms = $booking->rooms()->get();
    $totalWeight = (float) $rooms->sum('weight');

    expect($finalized->status)->toBe(CleaningBookingStatus::WorkerAssigned)
        ->and($finalized->is_pricing_final)->toBeTrue()
        ->and((float) $finalized->discount_amount)->toBe(300.0)
        ->and((float) $finalized->total_price)->toBe(2700.0)
        ->and($assignments)->toHaveCount($workerCount);
    $expectedShares = [];
    foreach ($assignments as $assignment) {
        $workerWeight = (float) $rooms
            ->where('assigned_worker_id', $assignment->worker_id)
            ->sum('weight');
        $expectedShare = round(3000 * ($workerWeight / $totalWeight) * 0.9, 2);
        $expectedShares[] = $expectedShare;
        expect((float) $assignment->service_share_amount)->toBe($expectedShare)
            ->and((float) $assignment->worker_amount)->toBe($expectedShare)
            ->and((float) $assignment->travel_fee)->toBe(0.0);
    }
    expect(round((float) $assignments->sum('service_share_amount'), 2))->toBe(round(array_sum($expectedShares), 2))
        ->and((float) $redemption->fresh()->subtotal)->toBe(3000.0)
        ->and((float) $redemption->fresh()->discount_amount)->toBe(300.0);

    $teamService->recalculateBookingTeam($finalized->fresh(), finalizeBooking: true);
    $repeatedAssignments = CleaningBookingWorkerAssignment::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('accepted_at')
        ->orderBy('id')
        ->get();
    expect((float) $booking->fresh()->discount_amount)->toBe(300.0)
        ->and((float) $booking->fresh()->total_price)->toBe(2700.0)
        ->and($repeatedAssignments->pluck('service_share_amount')->map(fn ($amount) => (float) $amount)->all())
        ->toBe($assignments->pluck('service_share_amount')->map(fn ($amount) => (float) $amount)->all());
})->with([2, 3]);

it('keeps pending coupon assignment shares gross-correct after admin price edits', function (int $workerCount): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 0,
        'commission_type' => 'percent',
        'commission_fixed_amount' => null,
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 75,
        'travel_per_km' => 10,
        'travel_distance_start_point' => 'worker_home',
    ]);

    $coupon = PlatformCoupon::query()->create([
        'code' => 'PENDING-'.uniqid(),
        'title_ar' => 'كوبون تنظيف',
        'title_en' => 'Cleaning coupon',
        'description_ar' => 'اختبار تعديل سعر حجز معلق',
        'description_en' => 'Pending booking price edit regression',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
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

    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'number_of_workers' => $workerCount,
        'booking_kind' => 'standard',
        'property_type' => 'apartment',
        'address_latitude' => 36.20,
        'address_longitude' => 37.15,
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
        'property_details' => [
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
            ],
        ],
    ]);

    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking);
    $worker = Worker::factory()->create([
        'home_address' => 'Same location',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
    ]);
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);
    $booking->rooms()->orderBy('planned_worker_slot')->first()->update([
        'assigned_worker_id' => $worker->id,
    ]);
    $redemption = PlatformCouponRedemption::query()->create([
        'platform_coupon_id' => $coupon->id,
        'user_id' => $customer->id,
        'section' => PlatformCoupon::SECTION_CLEANING,
        'order_type' => $booking->getMorphClass(),
        'order_id' => $booking->id,
        'coupon_code' => $coupon->code,
        'subtotal' => (float) $booking->subtotal_before_discount,
        'discount_amount' => (float) $booking->discount_amount,
    ]);

    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: false);
    $booking = app(CleaningBookingAdminPricingService::class)->setBasePrice($booking->fresh(), 5000);
    $assignment->refresh();
    $redemption->refresh();

    $assignedRoomWeight = (float) $booking->rooms()->where('assigned_worker_id', $worker->id)->sum('weight');
    $totalRoomWeight = (float) $booking->rooms()->sum('weight');
    $couponBreakdown = app(Modules\Cleaning\Services\CleaningCouponPricingService::class)
        ->storedBreakdown($booking);
    $grossServiceShare = round((float) $booking->base_price * ($assignedRoomWeight / $totalRoomWeight), 2);
    $expectedServiceShare = round($grossServiceShare * 0.9, 2);
    expect($booking->status)->toBe(CleaningBookingStatus::Pending)
        ->and($booking->is_pricing_final)->toBeFalse()
        ->and((float) $booking->base_price)->toBe(5000.0)
        ->and((float) $booking->discount_amount)->toBe(500.0)
        ->and((float) $couponBreakdown['assignmentWorkerNetFactor'])->toBe(0.9)
        ->and((float) $booking->discount_amount)->toBeGreaterThan(0)
        ->and((float) $redemption->subtotal)->toBe((float) $booking->subtotal_before_discount)
        ->and((float) $redemption->discount_amount)->toBe(500.0)
        ->and((float) $assignment->service_share_amount)->toBe($expectedServiceShare)
        ->and((float) $assignment->worker_amount)->toBe($expectedServiceShare + 75.0)
        ->and((float) $assignment->travel_fee)->toBe(75.0)
        ->and($grossServiceShare)->toBeGreaterThan(0.0);

    app(CleaningBookingTeamService::class)->recalculateBookingTeam($booking->fresh(), finalizeBooking: false);
    $assignment->refresh();
    expect((float) $assignment->service_share_amount)->toBe($expectedServiceShare)
        ->and((float) $assignment->worker_amount)->toBe($expectedServiceShare + 75.0);

    $beforeRepeatedEdit = (float) $assignment->worker_amount;
    $booking->forceFill(['total_price' => (float) $booking->total_price + 1])->save();
    $assignment->refresh();
    expect((float) $assignment->service_share_amount)->toBe($expectedServiceShare)
        ->and((float) $assignment->worker_amount)->toBe($expectedServiceShare + 75.0);

    $booking = app(CleaningBookingAdminPricingService::class)->setBasePrice($booking, 5200);
    $assignment->refresh();
    $assignedRoomWeight = (float) $booking->rooms()->where('assigned_worker_id', $worker->id)->sum('weight');
    $totalRoomWeight = (float) $booking->rooms()->sum('weight');
    $couponBreakdown = app(Modules\Cleaning\Services\CleaningCouponPricingService::class)
        ->storedBreakdown($booking);
    $expectedServiceShare = round(
        ((float) $booking->base_price * ($assignedRoomWeight / $totalRoomWeight)) * 0.9,
        2,
    );
    expect((float) $assignment->service_share_amount)->toBe($expectedServiceShare)
        ->and((float) $assignment->worker_amount)->toBe($expectedServiceShare + 75.0)
        ->and((float) $assignment->worker_amount)->toBeGreaterThan($beforeRepeatedEdit)
        ->and((float) $assignment->travel_fee)->toBe(75.0)
        ->and((float) $booking->discount_amount)->toBeGreaterThan(0);
})->with([2, 3]);
