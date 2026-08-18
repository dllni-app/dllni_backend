<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningPricingCalculator;
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

it('uses travel per kilometre as the minimum transport fee', function (): void {
    $pricing = app(CleaningPricingCalculator::class)->finalizedForCoordinates(
        100,
        0,
        36.2,
        37.1,
        36.2,
        37.1,
    );

    expect($pricing['distanceKm'])->toBe(0.0)
        ->and($pricing['travelFee'])->toBe(10.0);
});

it('prices regular cleaning worker previews from the next planned room slot', function (): void {
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

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom.small.1',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Bedroom 1',
        'weight' => 1.0,
        'planned_worker_slot' => 1,
    ]);
    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bathroom.small.1',
        'room_type' => 'bathroom',
        'room_size' => 'small',
        'display_label' => 'Bathroom 1',
        'weight' => 0.8,
        'planned_worker_slot' => 2,
    ]);

    $firstWorker = Worker::factory()->create([
        'home_address' => 'Same location',
        'home_latitude' => 36.2,
        'home_longitude' => 37.1,
    ]);
    $secondWorker = Worker::factory()->create([
        'home_address' => 'Same location',
        'home_latitude' => 36.2,
        'home_longitude' => 37.1,
    ]);

    $service = app(WorkerOrderSolvencyService::class);
    $firstOffer = $service->workerOfferForBooking($firstWorker, $booking);

    expect($firstOffer['workerSlot'])->toBe(1)
        ->and($firstOffer['roomCount'])->toBe(1)
        ->and($firstOffer['roomsWeight'])->toBe(1.0)
        ->and($firstOffer['serviceShareAmount'])->toBe(166.67)
        ->and($firstOffer['travelFee'])->toBe(10.0);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $firstWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1.0,
        'service_share_amount' => 166.67,
        'travel_fee' => 10,
        'admin_margin_amount' => 42,
        'worker_amount' => 134.67,
        'currency' => 'SYP',
    ]);

    $secondOffer = $service->workerOfferForBooking($secondWorker, $booking->fresh());

    expect($secondOffer['workerSlot'])->toBe(2)
        ->and($secondOffer['roomCount'])->toBe(1)
        ->and($secondOffer['roomsWeight'])->toBe(0.8)
        ->and($secondOffer['serviceShareAmount'])->toBe(133.33)
        ->and($secondOffer['travelFee'])->toBe(10.0);
});

it('keeps event assistance worker pricing evenly split', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'property_type' => 'event_assistance',
        'property_details' => [
            'event_type' => 'birthday',
            'guest_count' => 20,
            'venue_type' => 'apartment',
            'hours' => 2,
        ],
        'number_of_workers' => 3,
        'base_price' => 1200,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'total_price' => 1500,
        'is_pricing_final' => false,
        'estimated_hours' => 2,
        'total_hours' => 2,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    $worker = Worker::factory()->create([
        'home_address' => 'Same location',
        'home_latitude' => 36.2,
        'home_longitude' => 37.1,
    ]);

    $offer = app(WorkerOrderSolvencyService::class)->workerOfferForBooking($worker, $booking);

    expect($offer['workerSlot'])->toBeNull()
        ->and($offer['serviceShareAmount'])->toBe(400.0)
        ->and($offer['totalHours'])->toBe(2.0)
        ->and($offer['travelFee'])->toBe(10.0);
});
