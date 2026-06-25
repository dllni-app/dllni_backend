<?php

declare(strict_types=1);

use App\Enums\AlertType;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;
use Modules\Cleaning\Events\CleaningBookingPriceAdjustmentRequested;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingPriceAdjustmentRequest;

it('lets the assigned worker request a cleaning booking price adjustment', function (): void {
    Event::fake([CleaningBookingPriceAdjustmentRequested::class]);

    $workerUser = User::factory()->create(['email' => 'price-adjustment-worker@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::AwaitingStartVerification,
        'total_price' => 50000,
    ]);

    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/price-adjustment-requests", [
        'proposed_total_price' => 75000,
        'reason' => 'The current price does not cover the requested work.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.bookingId', $booking->id)
        ->assertJsonPath('data.workerId', $worker->id)
        ->assertJsonPath('data.oldTotalPrice', 50000)
        ->assertJsonPath('data.proposedTotalPrice', 75000)
        ->assertJsonPath('data.status', CleaningPriceAdjustmentRequestStatus::Pending->value);

    $this->assertDatabaseHas('cleaning_booking_price_adjustment_requests', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'old_total_price' => 50000,
        'proposed_total_price' => 75000,
        'status' => CleaningPriceAdjustmentRequestStatus::Pending->value,
    ]);

    $this->assertDatabaseHas('system_alerts', [
        'booking_id' => $booking->id,
        'booking_type' => CleaningBooking::class,
        'alert_type' => AlertType::PriceAdjustmentRequested->value,
    ]);

    Event::assertDispatched(CleaningBookingPriceAdjustmentRequested::class);
});

it('blocks duplicate pending price adjustment requests for the same booking', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'total_price' => 50000,
    ]);

    $payload = ['proposed_total_price' => 75000];

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/price-adjustment-requests", $payload)->assertCreated();
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/price-adjustment-requests", $payload)->assertUnprocessable();

    expect(CleaningBookingPriceAdjustmentRequest::query()
        ->where('cleaning_booking_id', $booking->id)
        ->where('status', CleaningPriceAdjustmentRequestStatus::Pending->value)
        ->count())->toBe(1);
});

it('blocks start work while a price adjustment request is pending', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'total_price' => 50000,
    ]);

    CleaningBookingPriceAdjustmentRequest::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'old_total_price' => 50000,
        'proposed_total_price' => 75000,
        'status' => CleaningPriceAdjustmentRequestStatus::Pending->value,
    ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/start-work")->assertUnprocessable();
});
