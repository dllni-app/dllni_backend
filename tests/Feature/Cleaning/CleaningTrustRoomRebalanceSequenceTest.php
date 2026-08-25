<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingTeamService;

it('can reshuffle only automatic room ownership while the team is forming so later higher-trust workers get the heavier balanced slot', function (): void {
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
                'bedroom' => ['small' => 0, 'medium' => 1, 'large' => 1],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'kitchen' => ['small' => 1, 'medium' => 1, 'large' => 1],
                'living_room' => ['small' => 1, 'medium' => 1, 'large' => 1],
            ],
        ],
        'number_of_workers' => 3,
        'base_price' => 14650,
        'addons_total' => 0,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking, null);

    $accept = function (int $trust, int $offset) use ($booking): Worker {
        $worker = Worker::factory()->create([
            'trust_score' => $trust,
            'home_address' => 'Same location',
            'home_latitude' => 36.2,
            'home_longitude' => 37.1,
        ]);

        CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now()->addSeconds($offset),
            'room_count' => 0,
            'rooms_weight' => 0,
            'service_share_amount' => 0,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'worker_amount' => 0,
            'currency' => 'SYP',
        ]);

        return $worker;
    };

    $low = $accept(40, 0);
    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: false);
    $lowAssignment = CleaningBookingWorkerAssignment::query()->where('worker_id', $low->id)->firstOrFail();
    expect((float) $lowAssignment->rooms_weight)->toBe(5.0);

    $high = $accept(95, 1);
    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: false);
    $lowAssignment->refresh();
    $highAssignment = CleaningBookingWorkerAssignment::query()->where('worker_id', $high->id)->firstOrFail();

    expect((float) $highAssignment->rooms_weight)->toBe(5.0)
        ->and((float) $lowAssignment->rooms_weight)->toBe(4.95);

    $medium = $accept(70, 2);
    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: true);
    $lowAssignment->refresh();
    $highAssignment->refresh();
    $mediumAssignment = CleaningBookingWorkerAssignment::query()->where('worker_id', $medium->id)->firstOrFail();

    expect((float) $highAssignment->rooms_weight)->toBe(5.0)
        ->and((float) $mediumAssignment->rooms_weight)->toBe(4.95)
        ->and((float) $lowAssignment->rooms_weight)->toBe(4.7)
        ->and($booking->rooms()->where('assignment_source', 'auto')->count())->toBe(9);
});
