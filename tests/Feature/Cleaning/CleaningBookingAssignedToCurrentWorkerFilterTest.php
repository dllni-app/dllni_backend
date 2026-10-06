<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

use function Pest\Laravel\getJson;

it('shows accepted pending multi-worker bookings in the current worker orders filter', function (): void {
    $workerUser = User::factory()->create(['email' => 'assigned-filter-worker@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    $customer = User::factory()->create(['email' => 'assigned-filter-customer@example.com']);

    $acceptedPendingBooking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $acceptedPendingBooking->id,
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

    $newUnacceptedBooking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    $otherWorker = Worker::factory()->financiallyEligible()->create(['user_id' => User::factory()->create()->id]);
    $otherWorkerBooking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $otherWorkerBooking->id,
        'worker_id' => $otherWorker->id,
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

    Sanctum::actingAs($workerUser);

    $response = getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[assignedToCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)
        ->toContain($acceptedPendingBooking->id)
        ->not->toContain($newUnacceptedBooking->id)
        ->not->toContain($otherWorkerBooking->id);
});

it('keeps a multi-session booking discoverable after restart when the worker is assigned only through session assignments', function (): void {
    $workerUser = User::factory()->create(['email' => 'session-assigned-filter-worker@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    $customer = User::factory()->create(['email' => 'session-assigned-filter-customer@example.com']);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => CleaningBookingStatus::AwaitingStartVerification->value,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
    ]);

    $sessions = collect([
        [1, CleaningBookingSessionStatus::AwaitingStartVerification, now()->addDay()->toDateString()],
        [2, CleaningBookingSessionStatus::WorkerAssigned, now()->addDays(2)->toDateString()],
        [3, CleaningBookingSessionStatus::WorkerAssigned, now()->addDays(3)->toDateString()],
    ])->map(function (array $row) use ($booking, $worker): CleaningBookingSession {
        [$sequence, $status, $date] = $row;
        $session = CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $booking->id,
            'sequence' => $sequence,
            'session_type' => 'event_day',
            'calculation_mode' => 'hours',
            'scheduled_date' => $date,
            'scheduled_time' => '09:00',
            'duration_hours' => 2,
            'required_workers' => 1,
            'coverage_status' => 'fully_covered',
            'status' => $status->value,
            'base_price' => 1000,
            'addons_total' => 0,
            'materials_total' => 0,
            'special_services_total' => 0,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'extension_fee_total' => 0,
            'cancellation_fee' => 0,
            'total_price' => 1000,
            'is_pricing_final' => true,
        ]);

        CleaningBookingSessionWorkerAssignment::query()->create([
            'cleaning_booking_session_id' => $session->id,
            'worker_id' => $worker->id,
            'status' => $sequence === 1
                ? CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value
                : CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now()->subHour(),
            'started_travel_at' => $sequence === 1 ? now()->subMinutes(30) : null,
            'arrived_at' => $sequence === 1 ? now()->subMinutes(10) : null,
            'service_share_amount' => 900,
            'travel_fee' => 0,
            'admin_margin_amount' => 100,
            'worker_amount' => 900,
            'currency' => 'SYP',
        ]);

        return $session;
    });

    expect($booking->workerAssignments()->exists())->toBeFalse();

    Sanctum::actingAs($workerUser);

    $response = getJson(
        '/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[assignedToCurrentWorker]=1&filter[status]=awaiting_start_verification'
    );

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($booking->id);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.bookingDaysCount', 3)
        ->assertJsonPath('data.schedule.daysCount', 3)
        ->assertJsonCount(3, 'data.schedule.sessions');

    expect($sessions)->toHaveCount(3);
});

it('does not show preferred-worker decision-required booking again to the worker who rejected it', function (): void {
    $workerUser = User::factory()->create(['email' => 'preferred-reject-filter-worker@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 80,
    ]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'assignment_mode' => CleaningAssignmentMode::PreferredWorker->value,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
    ]);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.assignmentMode', CleaningAssignmentMode::PreferredWorker->value)
        ->assertJsonPath('data.requiresPreferredWorkerRejectionDecision', true);

    $response = getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('id'))
        ->not->toContain($booking->id);
});

it('does not show converted preferred-worker booking again to the worker who rejected it after customer decision', function (): void {
    $customer = User::factory()->create(['email' => 'preferred-reject-convert-customer@example.com']);
    $workerUser = User::factory()->create(['email' => 'preferred-reject-convert-worker@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 80,
    ]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'assignment_mode' => CleaningAssignmentMode::PreferredWorker->value,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
    ]);

    Sanctum::actingAs($workerUser);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/reject")
        ->assertOk()
        ->assertJsonPath('data.requiresPreferredWorkerRejectionDecision', true);

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/preferred-worker-rejection/decision", [
        'decision' => 'convert_to_open',
    ])
        ->assertOk()
        ->assertJsonPath('data.assignmentMode', CleaningAssignmentMode::OpenCount->value)
        ->assertJsonPath('data.convertedFromPreferredWorker', true);

    Sanctum::actingAs($workerUser);

    $response = getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('id'))
        ->not->toContain($booking->id);
});
