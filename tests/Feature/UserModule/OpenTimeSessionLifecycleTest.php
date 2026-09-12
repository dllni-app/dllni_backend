<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningOpenTimeExtension;

it('runs the session extension and agreed end lifecycle idempotently through HTTP', function (): void {
    [$customer, $workerUser, $worker, $booking, $session, $assignment] = makeOpenTimeSessionLifecycleScenario();

    Sanctum::actingAs($customer);

    $extensionResponse = $this->withHeader('Idempotency-Key', 'open-time-session-extension-1')
        ->postJson(
            "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/open-time/extensions",
            ['minutes' => 30],
        )
        ->assertCreated()
        ->assertJsonPath('data.extension.status', 'pending')
        ->assertJsonPath('data.extension.requested_minutes', 30)
        ->assertJsonPath('data.openTime.sessionId', $session->id)
        ->assertJsonPath('data.openTime.pendingExtension.requestedMinutes', 30);

    $extensionId = (int) $extensionResponse->json('data.extension.id');

    $this->withHeader('Idempotency-Key', 'open-time-session-extension-1')
        ->postJson(
            "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/open-time/extensions",
            ['minutes' => 30],
        )
        ->assertCreated()
        ->assertJsonPath('data.extension.id', $extensionId);

    expect(CleaningOpenTimeExtension::query()->count())->toBe(1);

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/open-time/extensions/{$extensionId}/decision",
        ['decision' => 'accepted'],
    )
        ->assertOk()
        ->assertJsonPath('data.extension.status', 'accepted')
        ->assertJsonPath('data.openTime.expectedMaxMinutes', 150)
        ->assertJsonPath('data.openTime.pendingExtension', null);

    $extendedSession = $session->fresh();
    expect($extendedSession->open_time_expected_max_minutes)->toBe(150)
        ->and($extendedSession->duration_hours)->toBe(2.5)
        ->and($extendedSession->open_time_ceiling_ends_at?->equalTo(
            $extendedSession->work_started_at?->copy()->addMinutes(150),
        ))->toBeTrue();

    Sanctum::actingAs($customer);

    $firstEndRequest = $this->postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/open-time/end",
    )
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'pending');
    $requestedAt = $firstEndRequest->json('data.openTime.endRequestedAt');

    $this->postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/open-time/end",
    )
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'pending')
        ->assertJsonPath('data.openTime.endRequestedAt', $requestedAt);

    Sanctum::actingAs($workerUser);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/open-time/end/decision",
        ['decision' => 'accepted'],
    )
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'accepted')
        ->assertJsonPath('data.openTime.actualDurationMinutes', 31)
        ->assertJsonPath('data.openTime.billableDurationMinutes', 60)
        ->assertJsonPath('data.openTime.finalAmount', 120)
        ->assertJsonPath('data.openTime.isFinalized', true);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/open-time/end/decision",
        ['decision' => 'accepted'],
    )
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'accepted')
        ->assertJsonPath('data.openTime.finalAmount', 120);

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/open-time/end",
    )
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'accepted')
        ->assertJsonPath('data.openTime.isFinalized', true);

    $session->refresh();
    $booking->refresh();
    $assignment->refresh();

    expect($session->status)->toBe(CleaningBookingSessionStatus::Completed)
        ->and($session->base_price)->toBe(120.0)
        ->and($session->pricing_snapshot['actualDurationMinutes'])->toBe(31)
        ->and($session->pricing_snapshot['billableDurationMinutes'])->toBe(60)
        ->and($session->pricing_snapshot['finalAmount'])->toBe(120)
        ->and($assignment->status)->toBe(CleaningBookingWorkerAssignmentStatus::Completed)
        ->and($booking->status)->toBe(CleaningBookingStatus::Completed)
        ->and($booking->open_time_actual_minutes)->toBe(31)
        ->and($booking->open_time_billable_minutes)->toBe(60)
        ->and($booking->open_time_final_amount)->toBe(120.0)
        ->and($booking->open_time_finalized_at)->not->toBeNull();
});

it('records a rejected session end without stopping the running timer', function (): void {
    [$customer, $workerUser, , $booking, $session] = makeOpenTimeSessionLifecycleScenario();

    Sanctum::actingAs($customer);
    $this->postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/open-time/end",
    )->assertOk();

    Sanctum::actingAs($workerUser);
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/open-time/end/decision",
        ['decision' => 'rejected', 'reason' => 'Work is not complete yet.'],
    )
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'rejected')
        ->assertJsonPath('data.openTime.terminationReason', 'Work is not complete yet.')
        ->assertJsonPath('data.openTime.isFinalized', false);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}/open-time/end/decision",
        ['decision' => 'rejected', 'reason' => 'Work is not complete yet.'],
    )
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'rejected');

    expect($session->fresh()->status)->toBe(CleaningBookingSessionStatus::InProgress)
        ->and($session->fresh()->work_finished_at)->toBeNull()
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::InProgress);
});

it('keeps the legacy parent open-time end lifecycle idempotent', function (): void {
    [$customer, $workerUser, $worker, $booking, $session] = makeOpenTimeSessionLifecycleScenario();
    $session->delete();
    $booking->forceFill([
        'worker_id' => $worker->id,
        'open_time_hourly_rate' => 120,
        'open_time_minimum_minutes' => 60,
        'open_time_rounding_minutes' => 15,
        'open_time_expected_max_minutes' => 120,
        'open_time_hard_max_minutes' => 240,
        'open_time_warning_minutes' => 30,
        'open_time_extension_options' => [15, 30, 60],
        'open_time_ceiling_ends_at' => now()->addMinutes(90),
        'work_started_at' => now()->subMinutes(30)->subSecond(),
    ])->saveQuietly();

    Sanctum::actingAs($customer);
    $first = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/open-time/end")
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'pending');
    $requestedAt = $first->json('data.openTime.endRequestedAt');

    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/open-time/end")
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'pending')
        ->assertJsonPath('data.openTime.endRequestedAt', $requestedAt);

    Sanctum::actingAs($workerUser);
    $decisionUrl = "/api/v1/cleaning-bookings/{$booking->id}/open-time/end/decision";
    $this->postJson($decisionUrl, ['decision' => 'accepted'])
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'accepted')
        ->assertJsonPath('data.openTime.actualDurationMinutes', 31)
        ->assertJsonPath('data.openTime.billableDurationMinutes', 60)
        ->assertJsonPath('data.openTime.finalAmount', 120)
        ->assertJsonPath('data.openTime.isFinalized', true);

    $this->postJson($decisionUrl, ['decision' => 'accepted'])
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'accepted')
        ->assertJsonPath('data.openTime.finalAmount', 120);

    Sanctum::actingAs($customer);
    $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/open-time/end")
        ->assertOk()
        ->assertJsonPath('data.openTime.endStatus', 'accepted')
        ->assertJsonPath('data.openTime.isFinalized', true);

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Completed)
        ->and($booking->fresh()->open_time_actual_minutes)->toBe(31)
        ->and($booking->fresh()->open_time_billable_minutes)->toBe(60)
        ->and($booking->fresh()->open_time_final_amount)->toBe(120.0);
});

/**
 * @return array{0:User,1:User,2:Worker,3:CleaningBooking,4:CleaningBookingSession,5:CleaningBookingSessionWorkerAssignment}
 */
function makeOpenTimeSessionLifecycleScenario(): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 90,
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'booking_kind' => 'open_time',
        'status' => CleaningBookingStatus::InProgress->value,
        'number_of_workers' => 1,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 2,
        'total_hours' => 2,
        'base_price' => 240,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 240,
        'is_pricing_final' => false,
    ]);
    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_OPEN_TIME,
        'calculation_mode' => 'hours',
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'open_time_expected_max_minutes' => 120,
        'open_time_hard_max_minutes' => 240,
        'open_time_ceiling_ends_at' => now()->addMinutes(90),
        'required_workers' => 1,
        'coverage_status' => 'fully_covered',
        'status' => CleaningBookingSessionStatus::InProgress->value,
        'base_price' => 240,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 240,
        'is_pricing_final' => false,
        'payment_status' => 'pending',
        'pricing_snapshot' => [
            'hourlyRate' => 120,
            'workerCount' => 1,
            'expectedMaxMinutes' => 120,
            'hardMaxMinutes' => 240,
            'minimumBillableMinutes' => 60,
            'roundingMinutes' => 15,
            'warningMinutes' => 30,
            'extensionOptions' => [15, 30, 60],
        ],
        'work_started_at' => now()->subMinutes(30)->subSecond(),
    ]);
    $assignment = CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
        'accepted_at' => now()->subHour(),
        'started_travel_at' => now()->subMinutes(45),
        'arrived_at' => now()->subMinutes(40),
        'start_approved_at' => now()->subMinutes(31),
        'work_started_at' => now()->subMinutes(30)->subSecond(),
        'service_share_amount' => 240,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 240,
        'currency' => 'SYP',
    ]);

    return [$customer, $workerUser, $worker, $booking, $session, $assignment];
}
