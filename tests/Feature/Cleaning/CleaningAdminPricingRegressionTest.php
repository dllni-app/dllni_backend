<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingAdminPricingService;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\Cleaning\Services\CleaningPricingCalculator;

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


it('keeps the full fixed transport allowance on coupon bookings throughout team acceptance', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 25,
        'commission_type' => 'percent',
        'cleaning_minimum_order_price' => 0,
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 200,
    ]);

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'booking_kind' => 'standard',
        'worker_id' => null,
        'assignment_mode' => 'open_count',
        'number_of_workers' => 2,
        'property_type' => 'apartment',
        'property_details' => [
            'cleaning_mode' => 'deep',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 2, 'medium' => 0, 'large' => 0],
            ],
        ],
        'base_price' => 4000,
        'minimum_order_applied' => false,
        'addons_total' => 0,
        'is_pricing_final' => false,
        'address_latitude' => null,
        'address_longitude' => null,
    ])->fresh();

    $coupon = PlatformCoupon::query()->create([
        'code' => 'TEAMTRANSPORT10',
        'title_ar' => 'خصم تنظيف',
        'title_en' => 'Cleaning discount',
        'description_ar' => 'اختبار',
        'description_en' => 'Test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'is_active' => true,
    ]);

    $booking->forceFill([
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
    ])->save();

    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking);

    $assertPricing = function (CleaningBooking $current, bool $final): void {
        expect((float) $current->travel_fee)->toBe(400.0)
            ->and((float) $current->subtotal_before_discount)->toBe(5400.0)
            ->and((float) $current->discount_amount)->toBe(500.0)
            ->and((float) $current->admin_margin_amount)->toBe(500.0)
            ->and((float) $current->total_price)->toBe(4900.0)
            ->and((bool) $current->is_pricing_final)->toBe($final);
    };

    $assertPricing($booking->fresh(), false);

    foreach ([false, true] as $final) {
        $worker = Worker::factory()->create();
        CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now(),
            'room_count' => 0,
            'rooms_weight' => 0,
            'service_share_amount' => 0,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'worker_amount' => 0,
            'currency' => 'SYP',
        ]);

        $booking = $teamService->recalculateBookingTeam($booking, $final);
        $assertPricing($booking, $final);
        expect((float) $booking->workerAssignments()->sum('travel_fee'))->toBe($final ? 400.0 : 200.0);
    }

    expect($booking->status)->toBe(CleaningBookingStatus::WorkerAssigned);
});

it('rounds a fractional transport allowance per worker consistently', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 75.25,
    ]);

    $calculator = app(CleaningPricingCalculator::class);
    $worker = Worker::factory()->create();
    $individual = $calculator->finalizedForWorker(1000, 0, null, null, $worker);
    $team = $calculator->provisional(3000, 0, 0, 3);

    expect($individual['travelFee'])->toBe(76.0)
        ->and($team['travelFee'])->toBe(228.0);
});
