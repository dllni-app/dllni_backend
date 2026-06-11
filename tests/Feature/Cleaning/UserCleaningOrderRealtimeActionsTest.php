<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\ArrivalVerified;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Events\ServiceExtensionRequested;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

/** @var Illuminate\Foundation\Testing\TestCase $this */
beforeEach(function () {
    cleaningRealtimeBillingPolicy();
});

function cleaningRealtimeBillingPolicy(): CleaningBillingPolicy
{
    return CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
}

it('confirms start verification with a 4-digit code and waits for worker start confirmation', function () {
    Event::fake([CleaningBookingTrackingUpdated::class, ArrivalVerified::class]);

    $customer = User::factory()->create(['email' => 'customer-start-verify@example.com']);
    $workerUser = User::factory()->create(['email' => 'worker-start-verify@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($customer);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => cleaningRealtimeBillingPolicy()->id,
        'status' => CleaningBookingStatus::AwaitingStartVerification,
        'arrived_at' => now()->subMinutes(2),
    ]);

    DB::table('booking_security_codes')->insert([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'code' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'code_hash' => hash_hmac('sha256', '1234', (string) config('app.key')),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/start-verification/confirm", [
        'code' => '1234',
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('awaiting_worker_start_confirmation');
    expect($response->json('message'))->toBeString();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
        'work_started_at' => null,
    ]);
    $this->assertDatabaseHas('booking_security_codes', [
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
    ]);

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->tracking['status'] === CleaningBookingStatus::AwaitingWorkerStartConfirmation->value;
    });

    Event::assertDispatched(ArrivalVerified::class, function (ArrivalVerified $event) use ($booking, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->status === CleaningBookingStatus::AwaitingWorkerStartConfirmation->value;
    });
});

it('confirms completion for a waiting booking', function () {
    Event::fake([CleaningBookingTrackingUpdated::class, CompletionDecisionMade::class]);

    $customer = User::factory()->create(['email' => 'customer-complete-confirm@example.com']);
    $workerUser = User::factory()->create(['email' => 'worker-complete-confirm@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($customer);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => cleaningRealtimeBillingPolicy()->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinutes(10),
    ]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/confirm");

    $response->assertOk();
    expect($response->json('data.status'))->toBe('completed');
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($booking): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->tracking['status'] === CleaningBookingStatus::Completed->value;
    });

    Event::assertDispatched(CompletionDecisionMade::class, function (CompletionDecisionMade $event) use ($booking, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->decision === 'approved';
    });
});

it('rejects completion and reopens the booking', function () {
    Event::fake([CompletionDecisionMade::class]);

    $customer = User::factory()->create(['email' => 'customer-complete-reject@example.com']);
    $workerUser = User::factory()->create(['email' => 'worker-complete-reject@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($customer);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => cleaningRealtimeBillingPolicy()->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinutes(10),
    ]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/reject", [
        'reason' => 'Not finished yet',
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('in_progress');
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);
    expect($booking->fresh()->work_finished_at)->toBeNull();

    Event::assertDispatched(CompletionDecisionMade::class, function (CompletionDecisionMade $event) use ($booking, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->decision === 'rejected';
    });
});

it('requests a completion extension', function () {
    Event::fake([CompletionDecisionMade::class, ServiceExtensionRequested::class]);

    $customer = User::factory()->create(['email' => 'customer-complete-extend@example.com']);
    $workerUser = User::factory()->create(['email' => 'worker-complete-extend@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($customer);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => cleaningRealtimeBillingPolicy()->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subMinutes(10),
    ]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/extend-time", [
        'additionalMinutes' => 30,
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('time_extension_requested');
    expect($response->json('extensionPricing.requestedMinutes'))->toBe(30);
    expect($response->json('extensionPricing.matchedRange'))->toMatchArray([
        'startMinutes' => 16,
        'endMinutes' => 30,
        'label' => '16 - 30 minutes',
    ]);
    expect((float) $response->json('extensionPricing.calculatedExtensionPrice'))->toBe(4500.0);
    expect((float) $response->json('data.extensionFeeTotal'))->toBe(0.0);
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested->value,
    ]);
    $this->assertDatabaseHas('cleaning_time_warnings', [
        'booking_id' => $booking->id,
        'customer_response' => 'extend_time',
        'additional_minutes' => 30,
        'quoted_amount' => 4500.00,
        'quoted_currency' => (string) config('app.currency', 'SYP'),
    ]);

    Event::assertDispatched(CompletionDecisionMade::class, function (CompletionDecisionMade $event) use ($booking, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->decision === 'extension_requested';
    });

    Event::assertDispatched(ServiceExtensionRequested::class, function (ServiceExtensionRequested $event) use ($booking, $worker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $worker->id
            && $event->requestedMinutes === 30
            && $event->additionalAmount === 4500.0
            && $event->currency === (string) config('app.currency', 'SYP');
    });
});

it('rejects completion extension requests above 90 minutes', function () {
    $customer = User::factory()->create(['email' => 'customer-complete-extend-too-long@example.com']);
    $workerUser = User::factory()->create(['email' => 'worker-complete-extend-too-long@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($customer);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => cleaningRealtimeBillingPolicy()->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
    ]);

    $response = $this->postJson("/api/v1/user/cleaning/orders/{$booking->id}/completion/extend-time", [
        'additionalMinutes' => 91,
    ]);

    $response->assertUnprocessable();
    $this->assertDatabaseMissing('cleaning_time_warnings', [
        'booking_id' => $booking->id,
        'additional_minutes' => 91,
    ]);
});
