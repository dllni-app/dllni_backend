<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CleaningOrderAwaitingCustomerCompletion;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningCustomerIncident;

function customerIncidentFixture(int $workers = 1): array
{
    Event::fake([CleaningBookingTrackingUpdated::class, CleaningOrderAwaitingCustomerCompletion::class]);
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'number_of_workers' => $workers,
        'status' => CleaningBookingStatus::InProgress,
        'work_started_at' => now()->subHour(),
    ]);

    return [$customer, $workerUser, $booking];
}

it('completes individual work while saving an admin-only incident', function (): void {
    [$customer, $workerUser, $booking] = customerIncidentFixture();
    Sanctum::actingAs($workerUser);
    $report = ['categories' => ['mistreatment', 'unsafe_environment'], 'description' => 'Private incident description'];
    $response = $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete", ['customerIncident' => $report]);
    $response->assertOk()->assertJsonPath('data.status', CleaningBookingStatus::AwaitingCustomerCompletion->value);
    expect($response->getContent())->not->toContain($report['description']);
    $record = CleaningCustomerIncident::query()->sole();
    expect($record->customer_id)->toEqual($customer->id)
        ->and($record->reporter_user_id)->toEqual($workerUser->id)
        ->and($record->reporter_role)->toBe('worker')
        ->and($record->categories)->toBe($report['categories']);
    Sanctum::actingAs($customer);
    $customerResponse = $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")->assertOk();
    expect($customerResponse->getContent())->not->toContain($report['description']);
});

it('rejects invalid reports without finishing work', function (): void {
    [, $workerUser, $booking] = customerIncidentFixture();
    Sanctum::actingAs($workerUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete", [
        'customerIncident' => ['categories' => ['not_a_category'], 'description' => '   '],
    ])->assertUnprocessable();
    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::InProgress);
    $this->assertDatabaseCount('cleaning_customer_incidents', 0);
});

it('does not allow a team worker to report a customer', function (): void {
    [, $workerUser, $booking] = customerIncidentFixture(2);
    Sanctum::actingAs($workerUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete", [
        'customerIncident' => ['categories' => ['payment_problem'], 'description' => 'Payment issue'],
    ])->assertForbidden();
    $this->assertDatabaseCount('cleaning_customer_incidents', 0);
});

it('allows ordinary completion without creating an incident', function (): void {
    [, $workerUser, $booking] = customerIncidentFixture();
    Sanctum::actingAs($workerUser);
    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/complete")
        ->assertOk()->assertJsonPath('data.status', CleaningBookingStatus::AwaitingCustomerCompletion->value);
    $this->assertDatabaseCount('cleaning_customer_incidents', 0);
});
