<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningPreferredWorkerFallbackService;

beforeEach(function (): void {
    Notification::fake();
    Queue::fake();
});

it('never silently converts a recurring preferred worker booking to the open pool', function (): void {
    [$booking, $worker] = makePreferredWorkerFallbackScenario();

    makePreferredWorkerFallbackSession($booking, 1);
    makePreferredWorkerFallbackSession($booking, 2);

    $converted = app(CleaningPreferredWorkerFallbackService::class)
        ->convertToOpenIfEligible($booking->fresh());

    $booking->refresh();

    expect($converted)->toBeFalse()
        ->and($booking->resolvedAssignmentMode())->toBe(CleaningAssignmentMode::PreferredWorker->value)
        ->and((int) $booking->preferred_worker_id)->toBe((int) $worker->id)
        ->and((bool) $booking->converted_from_preferred_worker)->toBeFalse()
        ->and($booking->converted_from_preferred_worker_at)->toBeNull();
});

it('keeps the legacy fallback available for an ordinary preferred worker booking', function (): void {
    [$booking] = makePreferredWorkerFallbackScenario();

    $converted = app(CleaningPreferredWorkerFallbackService::class)
        ->convertToOpenIfEligible($booking->fresh());

    $booking->refresh();

    expect($converted)->toBeTrue()
        ->and($booking->resolvedAssignmentMode())->toBe(CleaningAssignmentMode::OpenCount->value)
        ->and($booking->preferred_worker_id)->toBeNull()
        ->and((bool) $booking->converted_from_preferred_worker)->toBeTrue()
        ->and($booking->converted_from_preferred_worker_at)->not->toBeNull();
});

/** @return array{0:CleaningBooking,1:Worker} */
function makePreferredWorkerFallbackScenario(): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 90,
    ]);

    // Create in a non-pending state so the observer does not enqueue the real
    // delayed fallback while arranging this focused service-level scenario.
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'preferred_worker_id' => null,
        'worker_id' => null,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    $booking->forceFill([
        'status' => CleaningBookingStatus::Pending->value,
        'assignment_mode' => CleaningAssignmentMode::PreferredWorker->value,
        'preferred_worker_id' => $worker->id,
        'converted_from_preferred_worker' => false,
        'converted_from_preferred_worker_at' => null,
    ])->saveQuietly();

    return [$booking->fresh(), $worker];
}

function makePreferredWorkerFallbackSession(
    CleaningBooking $booking,
    int $sequence,
): CleaningBookingSession {
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'recurring_cleaning',
        'calculation_mode' => 'estimated_hours',
        'scheduled_date' => now()->addDays($sequence + 1)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
        'status' => CleaningBookingSessionStatus::Scheduled,
        'base_price' => 3000,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 3300,
        'is_pricing_final' => true,
    ]);
}
