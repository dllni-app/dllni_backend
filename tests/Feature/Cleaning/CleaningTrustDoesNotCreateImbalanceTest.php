<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingTeamService;

it('does not create extra workload for a high-trust worker when the rooms divide evenly', function (): void {
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

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'property_type' => 'apartment',
        'property_details' => [
            'room_size_breakdown' => [
                'bedroom' => ['small' => 0, 'medium' => 0, 'large' => 15],
            ],
        ],
        'number_of_workers' => 3,
        'base_price' => 3000,
        'addons_total' => 0,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking, null);

    foreach ([100, 60, 20] as $index => $trustScore) {
        $worker = Worker::factory()->create([
            'trust_score' => $trustScore,
            'home_address' => 'Same location',
            'home_latitude' => 36.2,
            'home_longitude' => 37.1,
        ]);

        CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now()->addSeconds($index),
            'room_count' => 0,
            'rooms_weight' => 0,
            'service_share_amount' => 0,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'worker_amount' => 0,
            'currency' => 'SYP',
        ]);
    }

    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: true);

    $assignments = CleaningBookingWorkerAssignment::query()
        ->where('cleaning_booking_id', $booking->id)
        ->get();

    expect($assignments->pluck('room_count')->sort()->values()->all())->toBe([5, 5, 5])
        ->and($assignments->pluck('rooms_weight')->map(fn ($value) => (float) $value)->sort()->values()->all())
        ->toBe([10.0, 10.0, 10.0]);
});
