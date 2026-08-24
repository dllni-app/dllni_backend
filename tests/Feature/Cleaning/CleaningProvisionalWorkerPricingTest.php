<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;

beforeEach(function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 10,
            'travel_distance_start_point' => 'worker_home',
        ],
    );
});

it('keeps a provisional equal service share for an accepted worker before rooms are assigned', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'property_type' => 'apartment',
        'number_of_workers' => 2,
        'base_price' => 300,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 75,
        'total_price' => 375,
        'is_pricing_final' => false,
        'estimated_hours' => 1,
        'total_hours' => 1,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    foreach ([1, 2] as $index) {
        CleaningBookingRoom::query()->create([
            'cleaning_booking_id' => $booking->id,
            'room_key' => "bedroom.small.{$index}",
            'room_type' => 'bedroom',
            'room_size' => 'small',
            'display_label' => "Bedroom {$index}",
            'weight' => 1.0,
            'planned_worker_slot' => null,
        ]);
    }

    $worker = Worker::factory()->create([
        'home_address' => 'Same location',
        'home_latitude' => 36.2,
        'home_longitude' => 37.1,
    ]);

    $assignment = CleaningBookingWorkerAssignment::query()->create([
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

    app(CleaningBookingTeamService::class)->recalculateBookingTeam($booking, false);

    $assignment->refresh();
    $booking->refresh();

    expect((float) $assignment->service_share_amount)->toBe(150.0)
        ->and((float) $assignment->travel_fee)->toBe(10.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(38.0)
        ->and((float) $assignment->worker_amount)->toBe(122.0)
        ->and($booking->status)->toBe(CleaningBookingStatus::Pending)
        ->and((bool) $booking->is_pricing_final)->toBeFalse();
});

it('repairs the worker offer view for an existing provisional assignment stored with a zero service share', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'property_type' => 'apartment',
        'number_of_workers' => 2,
        'base_price' => 300,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 75,
        'total_price' => 375,
        'is_pricing_final' => false,
        'estimated_hours' => 1,
        'total_hours' => 1,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    foreach ([1, 2] as $index) {
        CleaningBookingRoom::query()->create([
            'cleaning_booking_id' => $booking->id,
            'room_key' => "bedroom.small.{$index}",
            'room_type' => 'bedroom',
            'room_size' => 'small',
            'display_label' => "Bedroom {$index}",
            'weight' => 1.0,
            'planned_worker_slot' => null,
        ]);
    }

    $worker = Worker::factory()->create([
        'home_address' => 'Same location',
        'home_latitude' => 36.2,
        'home_longitude' => 37.1,
    ]);

    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 10,
        'admin_margin_amount' => 0,
        'worker_amount' => 10,
        'currency' => 'SYP',
    ]);

    $offer = app(WorkerOrderSolvencyService::class)->workerOfferForBooking(
        $worker,
        $booking->fresh(),
        $assignment,
    );

    expect($offer['serviceShareAmount'])->toBe(150.0)
        ->and($offer['travelFee'])->toBe(10.0)
        ->and($offer['adminMarginAmount'])->toBe(38.0)
        ->and($offer['workerAmount'])->toBe(122.0)
        ->and($offer['totalPrice'])->toBe(122.0)
        ->and($offer['workerSlot'])->toBe(1)
        ->and($offer['isPreview'])->toBeTrue()
        ->and($offer['isPricingFinal'])->toBeFalse();
});
