<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Http\Middleware\RejectUnapprovedV3WorkerLifecycle;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

uses(RefreshDatabase::class);

/** @return array{0:CleaningBooking,1:CleaningBookingSession,2:User} */
function v3WorkerAdmissionFixture(bool $v3 = true): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    Worker::factory()->create(['user_id' => $workerUser->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'booking_kind' => 'standard',
        'status' => 'pending',
        'number_of_workers' => 1,
    ]);
    $booking->forceFill(['contract_version' => $v3 ? 'v3' : 'v2'])->save();

    $appointment = now(config('cleaning_booking_v2.timezone', 'Asia/Damascus'))->addDay();
    $visit = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $appointment->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);

    return [$booking, $visit, $workerUser];
}

test('old worker API cannot accept an unfunded V3 visit through selected or all sessions', function (): void {
    [$booking, $visit, $worker] = v3WorkerAdmissionFixture();
    Sanctum::actingAs($worker);

    foreach ([
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-all",
        "/api/v1/cleaning-bookings/{$booking->id}/accept",
    ] as $url) {
        $this->postJson($url, ['sessionIds' => [$visit->id]])
            ->assertStatus(426)
            ->assertJsonPath('code', 'UPDATE_REQUIRED')
            ->assertJsonPath('requiredCapabilities.0', RejectUnapprovedV3WorkerLifecycle::CAPABILITY);
    }

    expect($visit->workerAssignments()->count())->toBe(0)
        ->and($booking->fresh()->status->value)->toBe('pending');
});

test('a self-declared V3 capable worker is still blocked until independent financial signing approval', function (): void {
    [$booking, $visit, $worker] = v3WorkerAdmissionFixture();
    Sanctum::actingAs($worker);
    $headers = [RejectUnapprovedV3WorkerLifecycle::HEADER => RejectUnapprovedV3WorkerLifecycle::CAPABILITY];

    foreach ([
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-all",
        "/api/v1/cleaning-bookings/{$booking->id}/accept",
    ] as $url) {
        $this->withHeaders($headers)->postJson($url, ['sessionIds' => [$visit->id]])
            ->assertStatus(503)
            ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
    }

    expect($visit->workerAssignments()->count())->toBe(0)
        ->and($booking->fresh()->status->value)->toBe('pending');
});

test('V3 start work and other worker lifecycle operations cannot be entered with a legacy APK', function (): void {
    [$booking, $visit, $worker] = v3WorkerAdmissionFixture();
    Sanctum::actingAs($worker);
    foreach ([
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$visit->id}/start-travel",
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$visit->id}/arrive",
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$visit->id}/start-work",
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$visit->id}/complete",
        "/api/v1/cleaning-bookings/{$booking->id}/start-work",
    ] as $url) {
        $this->postJson($url, [])->assertStatus(426)
            ->assertJsonPath('code', 'UPDATE_REQUIRED');
    }

    expect($visit->fresh()->status->value)->toBe('scheduled')
        ->and($visit->fresh()->clock_revision)->toBe(0);
});

test('existing V2 worker acceptance and lifecycle validation is unchanged by V3 admission gate', function (): void {
    [$booking, $visit, $worker] = v3WorkerAdmissionFixture(false);
    Sanctum::actingAs($worker);

    // Invalid V2 selection reaches the existing request validator: 422,
    // not the V3 upgrade/financial gate.
    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        ['sessionIds' => []],
    )->assertUnprocessable()
        ->assertJsonMissing(['code' => 'UPDATE_REQUIRED'])
        ->assertJsonMissing(['code' => 'V3_FINANCIAL_APPROVAL_REQUIRED']);

    expect($visit->workerAssignments()->count())->toBe(0)
        ->and($booking->fresh()->status->value)->toBe('pending');
});

test('non-worker clients cannot inspect V3 worker admission capabilities through mutation routes', function (): void {
    [$booking, $visit] = v3WorkerAdmissionFixture();
    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);

    $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/sessions/accept-selected",
        ['sessionIds' => [$visit->id]],
    )->assertNotFound();
});
