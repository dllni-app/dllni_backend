<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Illuminate\Http\Request;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;
use Modules\User\Http\Resources\UserCleaningBookingResource;

beforeEach(function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 1000,
        ],
    );
});

it('persists the same provisional customer price shown by the estimate', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'number_of_workers' => 2,
        'base_price' => 2500,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 2500,
        'is_pricing_final' => false,
    ])->fresh();

    expect((float) $booking->base_price)->toBe(2500.0)
        ->and((float) $booking->travel_fee)->toBe(0.0)
        ->and((float) $booking->admin_margin_amount)->toBe(625.0)
        ->and((float) $booking->total_price)->toBe(3125.0)
        ->and((bool) $booking->is_pricing_final)->toBeFalse();
});

it('returns the user-facing service value consistently in order details', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'number_of_workers' => 2,
        'base_price' => 2500,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 2500,
        'is_pricing_final' => false,
    ])->fresh();

    $payload = UserCleaningBookingResource::make($booking)->toArray(Request::create('/'));

    expect($payload['basePrice'])->toBe(3125.0)
        ->and($payload['servicePrice'])->toBe(3125.0)
        ->and($payload['bookingBasePrice'])->toBe(2500.0)
        ->and($payload['bookingAdminMargin'])->toBe(625.0)
        ->and($payload['totalPrice'])->toBe(3125.0)
        ->and($payload['travelFeePending'])->toBeTrue();
});

it('marks a worker quote as preview and exposes net earnings as its display total', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'number_of_workers' => 2,
        'base_price' => 2500,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 2500,
        'is_pricing_final' => false,
        'address_latitude' => 36.216499,
        'address_longitude' => 37.159408,
    ]);

    $worker = Worker::factory()->create([
        'home_address' => 'Aleppo',
        'home_latitude' => 36.17870971,
        'home_longitude' => 37.12582381,
    ]);

    $offer = app(WorkerOrderSolvencyService::class)->workerOfferForBooking($worker, $booking);

    expect($offer['serviceShareAmount'])->toBe(1250.0)
        ->and($offer['travelFee'])->toBe(5171.0)
        ->and($offer['adminMarginAmount'])->toBe(313.0)
        ->and($offer['grossTotalPrice'])->toBe(6421.0)
        ->and($offer['workerAmount'])->toBe(6108.0)
        ->and($offer['totalPrice'])->toBe(6108.0)
        ->and($offer['isPreview'])->toBeTrue()
        ->and($offer['isPricingFinal'])->toBeFalse();
});

it('keeps the finalized booking margin exact across multiple worker assignments', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'assignment_mode' => 'open_count',
        'number_of_workers' => 2,
        'base_price' => 2500,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 625,
        'total_price' => 3125,
        'is_pricing_final' => true,
    ]);

    $workers = Worker::factory()->count(2)->create();

    foreach ($workers as $worker) {
        CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now(),
            'room_count' => 1,
            'rooms_weight' => 1,
            'service_share_amount' => 1250,
            'travel_fee' => 500,
            'admin_margin_amount' => 313,
            'worker_amount' => 1437,
            'currency' => 'SYP',
        ]);
    }

    $booking->forceFill([
        'travel_fee' => 1000,
        'is_pricing_final' => true,
    ])->save();

    $booking->refresh();
    $assignments = CleaningBookingWorkerAssignment::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('id')
        ->get();

    expect((float) $booking->admin_margin_amount)->toBe(625.0)
        ->and((float) $booking->total_price)->toBe(4125.0)
        ->and((float) $assignments->sum('admin_margin_amount'))->toBe(625.0)
        ->and((float) $assignments->sum('worker_amount'))->toBe(2875.0);
});

it('does not overwrite an explicitly approved final price adjustment', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'assignment_mode' => 'open_count',
        'base_price' => 2500,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 625,
        'total_price' => 3125,
        'is_pricing_final' => true,
    ]);

    $booking->forceFill([
        'base_price' => 3500,
        'total_price' => 4125,
        'is_pricing_final' => true,
    ])->save();

    $booking->refresh();

    expect((float) $booking->base_price)->toBe(3500.0)
        ->and((float) $booking->admin_margin_amount)->toBe(625.0)
        ->and((float) $booking->total_price)->toBe(4125.0);
});
