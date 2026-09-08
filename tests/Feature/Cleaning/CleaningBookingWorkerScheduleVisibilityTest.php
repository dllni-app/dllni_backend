<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ],
    );

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'default_commission_rate' => 10.00,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'vat_rate' => 0.00,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0.00,
            'travel_per_km' => 0.00,
            'travel_distance_start_point' => 'worker_home',
        ],
    );
});

it('keeps the complete customer schedule while forbidding an unrelated customer', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::WorkerAssigned);
    $first = makeVisibilitySession($booking, 1, now()->addDay()->toDateString());
    $second = makeVisibilitySession($booking, 2, now()->addDays(2)->toDateString());

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.daysCount', 2)
        ->assertJsonPath('data.schedule.sessions.0.sessionId', $first->id)
        ->assertJsonPath('data.schedule.sessions.1.sessionId', $second->id);

    Sanctum::actingAs(User::factory()->create(['is_active' => true]));

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertForbidden();
});

it('returns only assigned event days and resolves nextSession from the worker-visible scope', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $worker = makeVisibilityWorker();
    $otherWorker = makeVisibilityWorker();
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::WorkerAssigned);

    $dayOne = makeVisibilitySession($booking, 1, now()->addDay()->toDateString());
    $dayTwo = makeVisibilitySession($booking, 2, now()->addDays(2)->toDateString());
    $dayThree = makeVisibilitySession($booking, 3, now()->addDays(3)->toDateString());

    makeVisibilityAssignment($dayOne, $worker, workerAmount: 3000);
    makeVisibilityAssignment($dayTwo, $otherWorker, workerAmount: 3500);
    makeVisibilityAssignment($dayThree, $worker, workerAmount: 4000);

    Sanctum::actingAs($worker->user);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.bookingDaysCount', 3)
        ->assertJsonPath('data.schedule.daysCount', 2)
        ->assertJsonPath('data.schedule.mySessionsCount', 2)
        ->assertJsonPath('data.schedule.nextSession.sessionId', $dayOne->id)
        ->assertJsonCount(2, 'data.schedule.sessions')
        ->assertJsonMissing(['sessionId' => $dayTwo->id])
        ->assertJsonPath('data.schedule.sessions.0.sessionId', $dayOne->id)
        ->assertJsonPath('data.schedule.sessions.1.sessionId', $dayThree->id);
});

it('scopes assignment payout data to the authenticated worker only', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $worker = makeVisibilityWorker();
    $otherWorker = makeVisibilityWorker();
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::WorkerAssigned, requiredWorkers: 2);
    $session = makeVisibilitySession(
        $booking,
        1,
        now()->addDay()->toDateString(),
        requiredWorkers: 2,
    );

    makeVisibilityAssignment($session, $worker, workerAmount: 3000);
    makeVisibilityAssignment($session, $otherWorker, workerAmount: 3500);

    Sanctum::actingAs($worker->user);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonCount(1, 'data.schedule.sessions.0.workerAssignments')
        ->assertJsonPath('data.schedule.sessions.0.workerAssignments.0.workerId', $worker->id)
        ->assertJsonPath('data.schedule.sessions.0.workerAssignments.0.workerAmount', 3000)
        ->assertJsonPath('data.schedule.sessions.0.myAssignment.workerId', $worker->id)
        ->assertJsonPath('data.schedule.sessions.0.myAssignment.workerAmount', 3000)
        ->assertJsonMissing(['workerId' => $otherWorker->id, 'workerAmount' => 3500]);
});

it('keeps a completed session visible to the worker for history and payout', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $worker = makeVisibilityWorker();
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::Completed);
    $session = makeVisibilitySession(
        $booking,
        1,
        now()->subDay()->toDateString(),
        CleaningBookingSessionStatus::Completed,
    );
    makeVisibilityAssignment(
        $session,
        $worker,
        CleaningBookingWorkerAssignmentStatus::Completed,
        workerAmount: 4200,
    );

    Sanctum::actingAs($worker->user);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.daysCount', 1)
        ->assertJsonPath('data.schedule.completedDaysCount', 1)
        ->assertJsonPath('data.schedule.sessions.0.sessionId', $session->id)
        ->assertJsonPath('data.schedule.sessions.0.myAssignment.workerAmount', 4200)
        ->assertJsonPath('data.schedule.nextSession', null);
});

it('removes a released day without changing the same worker sibling days', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $worker = makeVisibilityWorker();
    $replacement = makeVisibilityWorker();
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::WorkerAssigned);

    $dayOne = makeVisibilitySession($booking, 1, now()->addDay()->toDateString());
    $dayTwo = makeVisibilitySession($booking, 2, now()->addDays(2)->toDateString());
    $dayThree = makeVisibilitySession($booking, 3, now()->addDays(3)->toDateString());

    makeVisibilityAssignment($dayOne, $worker);
    $released = makeVisibilityAssignment($dayTwo, $worker);
    makeVisibilityAssignment($dayThree, $worker);

    $released->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
        'released_at' => now(),
        'released_reason' => 'Customer requested worker replacement',
    ])->save();
    makeVisibilityAssignment($dayTwo, $replacement);

    Sanctum::actingAs($worker->user);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonCount(2, 'data.schedule.sessions')
        ->assertJsonPath('data.schedule.sessions.0.sessionId', $dayOne->id)
        ->assertJsonPath('data.schedule.sessions.1.sessionId', $dayThree->id)
        ->assertJsonMissing(['sessionId' => $dayTwo->id]);

    expect($dayOne->workerAssignments()->where('worker_id', $worker->id)->first()?->isAccepted())->toBeTrue()
        ->and($dayThree->workerAssignments()->where('worker_id', $worker->id)->first()?->isAccepted())->toBeTrue();
});

it('allows a released-only worker to refetch an empty schedule so stale client state can be removed', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $worker = makeVisibilityWorker();
    $replacement = makeVisibilityWorker();
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::WorkerAssigned);
    $session = makeVisibilitySession($booking, 1, now()->addDay()->toDateString());

    $released = makeVisibilityAssignment($session, $worker);
    $released->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
        'released_at' => now(),
        'released_reason' => 'Reassigned',
    ])->save();
    makeVisibilityAssignment($session, $replacement);

    Sanctum::actingAs($worker->user);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.bookingDaysCount', 1)
        ->assertJsonPath('data.schedule.daysCount', 0)
        ->assertJsonPath('data.schedule.mySessionsCount', 0)
        ->assertJsonPath('data.schedule.nextSession', null)
        ->assertJsonCount(0, 'data.schedule.sessions');
});

it('forbids a worker with no legitimate relationship to a fully covered booking', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $worker = makeVisibilityWorker();
    $assignedWorker = makeVisibilityWorker();
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::WorkerAssigned);
    $session = makeVisibilitySession($booking, 1, now()->addDay()->toDateString());
    makeVisibilityAssignment($session, $assignedWorker);

    Sanctum::actingAs($worker->user);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertForbidden();
});

it('preserves eligible multi-day event pre-acceptance visibility and blocks an ineligible worker', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $eligible = makeVisibilityWorker();
    $ineligible = makeVisibilityWorker();
    $ineligible->forceFill(['is_suspended' => true])->save();
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::Pending);
    $first = makeVisibilitySession($booking, 1, now()->addDay()->toDateString());
    $second = makeVisibilitySession($booking, 2, now()->addDays(2)->toDateString());

    Sanctum::actingAs($eligible->user);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.daysCount', 2)
        ->assertJsonPath('data.schedule.sessions.0.sessionId', $first->id)
        ->assertJsonPath('data.schedule.sessions.1.sessionId', $second->id);

    Sanctum::actingAs($ineligible->user);

    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertForbidden();
});

it('rejects every worker lifecycle action against another workers session', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $worker = makeVisibilityWorker();
    $assignedWorker = makeVisibilityWorker();
    $booking = makeVisibilityBooking($customer, CleaningBookingStatus::WorkerAssigned);
    $session = makeVisibilitySession($booking, 1, now()->addDay()->toDateString());
    makeVisibilityAssignment($session, $assignedWorker);

    Sanctum::actingAs($worker->user);

    $base = "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}";

    $this->postJson("{$base}/start-travel")->assertUnprocessable();
    $this->postJson("{$base}/location", ['latitude' => 36.20, 'longitude' => 37.15])->assertForbidden();
    $this->postJson("{$base}/arrive")->assertUnprocessable();
    $this->getJson("{$base}/security-code")->assertUnprocessable();
    $this->postJson("{$base}/start-work")->assertUnprocessable();
    $this->postJson("{$base}/complete", ['message' => 'done'])->assertUnprocessable();
    $this->postJson("{$base}/cancel", ['reason' => 'not mine'])->assertUnprocessable();
    $this->postJson("{$base}/sos", [
        'emergency_type' => 'safety_threat',
        'message' => 'Need help',
    ])->assertForbidden();
});

function makeVisibilityWorker(): Worker
{
    $workingHours = [];
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $workingHours[$day] = [
            'available' => true,
            'data' => [['00:00' => '23:59']],
        ];
    }

    $user = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'gender' => 'male',
        'trust_score' => 90,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
        'default_working_hours' => $workingHours,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 100000,
            'debt_balance' => 0,
            'deposited_total' => 100000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 100000,
            'is_active' => true,
        ],
    );

    return $worker->fresh(['user', 'deposit']);
}

function makeVisibilityBooking(
    User $customer,
    CleaningBookingStatus $status,
    int $requiredWorkers = 1,
): CleaningBooking {
    return CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'property_type' => 'event_assistance',
        'property_details' => [
            'event_type' => 'birthday',
            'guest_count' => 25,
            'venue_type' => 'house',
            'custom_service' => 'Event support',
            'hours' => 4,
        ],
        'status' => $status->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'number_of_workers' => $requiredWorkers,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 4,
        'total_hours' => 4,
        'base_price' => 6000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 600,
        'cancellation_fee' => 0,
        'total_price' => 6600,
        'gender_preference' => 'any',
        'neighborhood_id' => null,
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);
}

function makeVisibilitySession(
    CleaningBooking $booking,
    int $sequence,
    string $date,
    CleaningBookingSessionStatus $status = CleaningBookingSessionStatus::WorkerAssigned,
    int $requiredWorkers = 1,
): CleaningBookingSession {
    $isCompleted = $status === CleaningBookingSessionStatus::Completed;

    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => $date,
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => $requiredWorkers,
        'coverage_status' => $status === CleaningBookingSessionStatus::Scheduled
            ? CleaningBookingSessionCoverageStatus::Searching
            : CleaningBookingSessionCoverageStatus::FullyCovered,
        'status' => $status,
        'base_price' => 3000,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 3300,
        'is_pricing_final' => $status !== CleaningBookingSessionStatus::Scheduled,
        'work_started_at' => $isCompleted ? now()->subHours(2) : null,
        'work_finished_at' => $isCompleted ? now()->subHour() : null,
    ]);
}

function makeVisibilityAssignment(
    CleaningBookingSession $session,
    Worker $worker,
    CleaningBookingWorkerAssignmentStatus $status = CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
    float $workerAmount = 3000,
): CleaningBookingSessionWorkerAssignment {
    return CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => $status,
        'accepted_at' => now()->subHour(),
        'work_started_at' => $status === CleaningBookingWorkerAssignmentStatus::Completed ? now()->subHours(2) : null,
        'work_finished_at' => $status === CleaningBookingWorkerAssignmentStatus::Completed ? now()->subHour() : null,
        'service_share_amount' => $workerAmount,
        'travel_fee' => 0,
        'admin_margin_amount' => 300,
        'worker_amount' => $workerAmount,
        'currency' => 'SYP',
    ]);
}
