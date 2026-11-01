<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
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


it('returns partially completed assigned bookings without exposing deprecated room-claim fields', function (): void {
    $workerUser = User::factory()->create(['email' => 'legacy-room-source-worker@example.com']);
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    $customer = User::factory()->create(['email' => 'legacy-room-source-customer@example.com']);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::PartiallyCompleted->value,
        'number_of_workers' => 1,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now()->subHour(),
        'service_share_amount' => 1000,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 1000,
        'currency' => 'SYP',
    ]);

    DB::table('cleaning_booking_rooms')->insert([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'legacy.system.room.1',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Legacy room',
        'weight' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otherWorker = Worker::factory()->create();
    DB::table('cleaning_booking_rooms')->insert([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'legacy.other.room.2',
        'room_type' => 'bathroom',
        'room_size' => 'small',
        'display_label' => 'Other worker room',
        'weight' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $legacyRoom = CleaningBookingRoom::query()
        ->where('cleaning_booking_id', $booking->id)
        ->firstOrFail();

    expect($legacyRoom->room_key)->toBe('legacy.system.room.1');

    Sanctum::actingAs($workerUser);

    $response = getJson(
        '/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[assignedToCurrentWorker]=1&filter[status]=partially_completed&perPage=10&page=1'
    );

    $response->assertOk();

    $item = collect($response->json('data'))
        ->firstWhere('id', $booking->id);

    expect($item)->not->toBeNull()
        ->and($item)->not->toHaveKey('roomAssignments')
        ->and(collect($item['roomTasks'])->pluck('roomKey')->all())->toContain('legacy.system.room.1', 'legacy.other.room.2')
        ->and($item['globalStatus'])->toBe('partially_completed');
});

it('does not use legacy room discovery for a supervised team without accepted visit proof', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->financiallyEligible()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'booking_kind' => 'supervised_team',
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::PartiallyCompleted->value,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now(),
        'service_share_amount' => 100,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 100,
        'currency' => 'SYP',
    ]);
    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'team.private.room.1',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Private team room',
        'weight' => 1,
    ]);

    Sanctum::actingAs($workerUser);
    $response = getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[assignedToCurrentWorker]=1&filter[status]=partially_completed');
    $response->assertOk();
    $visible = collect($response->json('data'))->firstWhere('id', $booking->id);

    if ($visible !== null) {
        expect(array_key_exists('roomAssignments', $visible))->toBeFalse()
            ->and($visible)->not->toHaveKeys(['customer', 'bookingTotalPrice', 'adminMargin']);
    } else {
        expect(collect($response->json('data'))->pluck('id'))->not->toContain($booking->id);
    }
});
