<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\Worker;
use Illuminate\Validation\ValidationException;
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

function makeAcceptedCleaningWorker(CleaningBooking $booking, int $trustScore, int $acceptedAfterSeconds): array
{
    $worker = Worker::factory()->create([
        'trust_score' => $trustScore,
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
}

it('repairs a legacy fifteen-room plan and immediately gives the accepted worker five rooms', function (): void {
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
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 3000,
        'is_pricing_final' => false,
        'estimated_hours' => 15,
        'total_hours' => 15,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking, null);

    // Simulate bookings created before automatic worker-slot planning existed.
    $booking->rooms()->update([
        'planned_worker_slot' => null,
        'planned_preferred_worker_id' => null,
        'assigned_worker_id' => null,
        'assignment_source' => null,
    ]);

    [$worker, $assignment] = makeAcceptedCleaningWorker($booking, 80, 0);

    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: false);
    $assignment->refresh();
    $booking->refresh();

    expect($assignment->room_count)->toBe(5)
        ->and((float) $assignment->rooms_weight)->toBe(10.0)
        ->and((float) $assignment->service_share_amount)->toBe(1000.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(100.0)
        ->and($booking->rooms()->where('assigned_worker_id', $worker->id)->count())->toBe(5)
        ->and($booking->rooms()->whereNotNull('planned_worker_slot')->count())->toBe(15)
        ->and((float) $booking->admin_margin_amount)->toBe(300.0)
        ->and((float) $booking->total_price)->toBe(3300.0);
});

it('keeps work as balanced as possible while giving the slightly heavier share to higher-trust workers', function (): void {
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
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 14650,
        'is_pricing_final' => false,
        'estimated_hours' => 9,
        'total_hours' => 9,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
    ]);

    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking, null);

    [$lowTrustWorker] = makeAcceptedCleaningWorker($booking, 40, 0);
    [$highTrustWorker] = makeAcceptedCleaningWorker($booking, 95, 1);
    [$mediumTrustWorker] = makeAcceptedCleaningWorker($booking, 70, 2);

    $teamService->recalculateBookingTeam($booking->fresh(), finalizeBooking: true);

    $assignments = CleaningBookingWorkerAssignment::query()
        ->where('cleaning_booking_id', $booking->id)
        ->get()
        ->keyBy('worker_id');

    $highWeight = (float) $assignments[$highTrustWorker->id]->rooms_weight;
    $mediumWeight = (float) $assignments[$mediumTrustWorker->id]->rooms_weight;
    $lowWeight = (float) $assignments[$lowTrustWorker->id]->rooms_weight;

    expect($highWeight)->toBe(5.0)
        ->and($mediumWeight)->toBe(4.95)
        ->and($lowWeight)->toBe(4.7)
        ->and($highWeight)->toBeGreaterThan($mediumWeight)
        ->and($mediumWeight)->toBeGreaterThan($lowWeight)
        ->and(round($highWeight - $lowWeight, 2))->toBeLessThanOrEqual(0.3);
});

it('does not rebuild and wipe the room plan after any worker has accepted', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => 'open_count',
        'property_type' => 'apartment',
        'property_details' => [
            'room_size_breakdown' => [
                'bedroom' => ['small' => 3, 'medium' => 0, 'large' => 0],
            ],
        ],
        'number_of_workers' => 3,
    ]);

    $teamService = app(CleaningBookingTeamService::class);
    $teamService->syncRooms($booking, null);
    makeAcceptedCleaningWorker($booking, 80, 0);

    expect(fn () => $teamService->syncRooms($booking->fresh(), null))
        ->toThrow(ValidationException::class);
});
