<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingTeamService;

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

it('assigns the accepted worker planned rooms and their proportional admin margin before the team is full', function (): void {
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

    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: false);
    $assignment->refresh();

    expect($assignment->room_count)->toBe(1)
        ->and((float) $assignment->rooms_weight)->toBe(1.0)
        ->and((float) $assignment->service_share_amount)->toBe(1000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(100.0)
        ->and((float) $assignment->travel_fee)->toBe(10.0)
        ->and((float) $assignment->worker_amount)->toBe(910.0);

    expect($booking->rooms()->where('assigned_worker_id', $worker->id)->count())->toBe(1);
});
