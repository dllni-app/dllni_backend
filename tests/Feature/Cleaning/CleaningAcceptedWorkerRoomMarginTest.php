<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;

beforeEach(function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 10,
            'travel_distance_start_point' => 'worker_home',
        ],
    );
});

it('returns the exact planned room ids in the worker offer before acceptance', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'property_type' => 'apartment',
        'property_details' => [
            'room_size_breakdown' => [
                'bedroom' => ['small' => 2],
            ],
        ],
        'number_of_workers' => 2,
        'base_price' => 2000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 2000,
        'is_pricing_final' => false,
        'estimated_hours' => 2,
        'total_hours' => 2,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    app(CleaningBookingTeamService::class)->syncRooms($booking, null);

    $expectedRoomIds = $booking->rooms()
        ->where('planned_worker_slot', 1)
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn ($id): int => (int) $id)
        ->all();

    $worker = Worker::factory()->create([
        'home_address' => 'Same location',
        'home_latitude' => 36.2,
        'home_longitude' => 37.1,
    ]);

    $offer = app(WorkerOrderSolvencyService::class)->workerOfferForBooking(
        $worker,
        $booking->fresh(),
    );

    expect($expectedRoomIds)->toHaveCount(1)
        ->and($offer['isPreview'])->toBeTrue()
        ->and($offer['workerSlot'])->toBe(1)
        ->and($offer['roomCount'])->toBe(1)
        ->and($offer['roomIds'])->toBe($expectedRoomIds);
});

it('assigns rooms immediately and deducts the admin percentage from all three workers by work share', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'property_type' => 'apartment',
        'property_details' => [
            'room_size_breakdown' => [
                'bedroom' => ['small' => 3],
            ],
        ],
        'number_of_workers' => 3,
        'base_price' => 3000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 3000,
        'is_pricing_final' => false,
        'estimated_hours' => 3,
        'total_hours' => 3,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking, null);

    expect($booking->rooms()->orderBy('planned_worker_slot')->pluck('planned_worker_slot')->all())
        ->toBe([1, 2, 3]);

    $createAcceptedWorker = function (int $acceptedAfterSeconds) use ($booking): array {
        $worker = Worker::factory()->create([
            'home_address' => 'Same location',
            'home_latitude' => 36.2,
            'home_longitude' => 37.1,
        ]);

        $assignment = CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now()->addSeconds($acceptedAfterSeconds),
            'room_count' => 0,
            'rooms_weight' => 0,
            'service_share_amount' => 0,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'worker_amount' => 0,
            'currency' => 'SYP',
        ]);

        return [$worker, $assignment];
    };

    [$firstWorker, $firstAssignment] = $createAcceptedWorker(0);

    // This is the screenshot state: one worker has accepted while two workers
    // are still missing. Their assigned room and administration share must be
    // available immediately instead of remaining zero until team completion.
    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: false);
    $firstAssignment->refresh();
    $provisionalBooking = $booking->fresh();

    expect($firstAssignment->room_count)->toBe(1)
        ->and((float) $firstAssignment->rooms_weight)->toBe(1.0)
        ->and((float) $firstAssignment->service_share_amount)->toBe(1000.0)
        ->and((float) $firstAssignment->admin_margin_amount)->toBe(100.0)
        ->and((float) $firstAssignment->travel_fee)->toBe(10.0)
        ->and((float) $firstAssignment->worker_amount)->toBe(1010.0)
        ->and((float) $provisionalBooking->admin_margin_amount)->toBe(300.0)
        ->and((float) $provisionalBooking->total_price)->toBe(3300.0);

    expect($booking->rooms()->where('assigned_worker_id', $firstWorker->id)->count())->toBe(1);

    [$secondWorker] = $createAcceptedWorker(1);
    [$thirdWorker] = $createAcceptedWorker(2);

    $finalBooking = $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: true);
    $assignments = CleaningBookingWorkerAssignment::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('accepted_at')
        ->orderBy('id')
        ->get();

    expect($assignments)->toHaveCount(3)
        ->and($assignments->pluck('room_count')->all())->toBe([1, 1, 1])
        ->and($assignments->pluck('service_share_amount')->map(fn ($value) => (float) $value)->all())
        ->toBe([1000.0, 1000.0, 1000.0])
        ->and($assignments->pluck('admin_margin_amount')->map(fn ($value) => (float) $value)->all())
        ->toBe([100.0, 100.0, 100.0])
        ->and((float) $assignments->sum('admin_margin_amount'))->toBe(300.0)
        ->and((float) $finalBooking->admin_margin_amount)->toBe(300.0);

    foreach ([$firstWorker, $secondWorker, $thirdWorker] as $worker) {
        expect($booking->rooms()->where('assigned_worker_id', $worker->id)->count())->toBe(1);
    }
});
