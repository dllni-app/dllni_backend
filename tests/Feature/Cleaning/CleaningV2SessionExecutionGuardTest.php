<?php

declare(strict_types=1);

use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

function b1GuardBooking(int $workers): array
{
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => $workers,
        'status' => 'worker_assigned',
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => $workers,
        'coverage_status' => 'searching',
        'status' => 'worker_assigned',
    ]);
    $worker = Worker::factory()->create();
    $session->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);

    return [$booking, $session, $worker];
}

it('permits attendance for an assigned worker and requires verification for subsequent session work', function (): void {
    [$booking, $session, $worker] = b1GuardBooking(2);
    Sanctum::actingAs($worker->user);
    $base = "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}";

    $this->postJson("$base/start-travel")->assertOk();
    $this->postJson("$base/arrive")->assertOk();

    $this->getJson("$base/security-code")->assertOk();
    $this->postJson("$base/start-work")->assertUnprocessable();
    $this->postJson("$base/complete")->assertUnprocessable();
});

it('rejects customer session mutations without required verification and completion state', function (): void {
    [$booking, $session] = b1GuardBooking(2);
    Sanctum::actingAs($booking->customer);

    foreach ([
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/start-verification/confirm",
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/completion/confirm",
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/completion/reject",
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/completion/extend-time",
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/cancel",
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/open-time/extensions",
        "/api/v1/user/cleaning/orders/{$booking->id}/sessions/{$session->id}/open-time/end",
    ] as $url) {
        $this->postJson($url)->assertUnprocessable();
    }
});

it('keeps solo session attendance and execution routing outside the V2 group guard', function (): void {
    [$booking, $session, $worker] = b1GuardBooking(1);
    Sanctum::actingAs($worker->user);
    $base = "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$session->id}";

    $this->postJson("$base/start-travel")->assertOk();
    $this->postJson("$base/arrive")->assertOk();
    $this->getJson("$base/security-code")
        ->assertOk()
        ->assertJsonMissing(['code' => 'CLEANING_V2_VISIT_EXECUTION_UNAVAILABLE']);
});

it('protects booking security code from workers without a booking-level assignment', function (): void {
    [$groupBooking, , $groupWorker] = b1GuardBooking(2);
    Sanctum::actingAs($groupWorker->user);
    $this->getJson("/api/v1/cleaning-bookings/{$groupBooking->id}/security-code")
        ->assertForbidden();

    [$soloBooking, , $soloWorker] = b1GuardBooking(1);
    Sanctum::actingAs($soloWorker->user);
    $solo = $this->getJson("/api/v1/cleaning-bookings/{$soloBooking->id}/security-code");
    expect($solo->json('code'))->not->toBe('CLEANING_V2_VISIT_EXECUTION_UNAVAILABLE');
});
