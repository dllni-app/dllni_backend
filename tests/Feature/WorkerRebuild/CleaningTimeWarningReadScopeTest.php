<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Models\EventBooking;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
});

it('hides another workers extension detail and never returns a raw booking', function (): void {
    $ownerUser = User::factory()->create();
    $ownerWorker = Worker::factory()->create(['user_id' => $ownerUser->id]);
    $strangerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $strangerUser->id]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $ownerWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'property_details' => ['private_note' => 'must-not-be-disclosed'],
    ]);
    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_id' => $ownerWorker->id,
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($strangerUser);
    $this->getJson("/api/v1/cleaning-time-warnings/{$warning->id}")->assertNotFound();

    Sanctum::actingAs($ownerUser);
    $response = $this->getJson("/api/v1/cleaning-time-warnings/{$warning->id}");
    $response->assertOk()->assertJsonPath('data.extensionFamily', 'fixedTimeWarning');
    expect($response->json('data.booking'))->toBe([
        'id' => $booking->id,
        'bookingNumber' => $booking->booking_number,
        'status' => CleaningBookingStatus::TimeExtensionRequested->value,
    ]);
});

it('limits nonworker extension lists and detail reads to the owning customer', function (): void {
    $customer = User::factory()->create();
    $otherCustomer = User::factory()->create();

    $ownBooking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'billing_policy_id' => $this->billingPolicy->id,
    ]);
    $otherBooking = CleaningBooking::factory()->create([
        'customer_id' => $otherCustomer->id,
        'billing_policy_id' => $this->billingPolicy->id,
    ]);
    $ownWarning = CleaningTimeWarning::query()->create([
        'booking_id' => $ownBooking->id,
        'booking_type' => 'cleaning_booking',
        'sent_at' => now(),
    ]);
    $otherWarning = CleaningTimeWarning::query()->create([
        'booking_id' => $otherBooking->id,
        'booking_type' => 'cleaning_booking',
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($customer);
    $response = $this->getJson('/api/v1/cleaning-time-warnings');
    $response->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe([$ownWarning->id]);
    $this->getJson("/api/v1/cleaning-time-warnings/{$ownWarning->id}")->assertOk();
    $this->getJson("/api/v1/cleaning-time-warnings/{$otherWarning->id}")->assertNotFound();

    Sanctum::actingAs($otherCustomer);
    $this->getJson("/api/v1/cleaning-time-warnings/{$ownWarning->id}")->assertNotFound();
});

it('protects other workers assigned warnings even when they share the same booking', function (): void {
    $firstUser = User::factory()->create();
    $firstWorker = Worker::factory()->create(['user_id' => $firstUser->id]);
    $secondUser = User::factory()->create();
    $secondWorker = Worker::factory()->create(['user_id' => $secondUser->id]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
    ]);
    foreach ([$firstWorker, $secondWorker] as $worker) {
        CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        ]);
    }

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'worker_id' => $firstWorker->id,
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($secondUser);
    $this->getJson("/api/v1/cleaning-time-warnings/{$warning->id}")->assertNotFound();
    expect(array_column($this->getJson('/api/v1/cleaning-time-warnings')->assertOk()->json('data'), 'id'))
        ->not->toContain($warning->id);

    Sanctum::actingAs($firstUser);
    $this->getJson("/api/v1/cleaning-time-warnings/{$warning->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data.booking');
});

it('restricts event booking warning details to its customer and targeted worker', function (): void {
    $customer = User::factory()->create();
    $targetUser = User::factory()->create();
    $targetWorker = Worker::factory()->create(['user_id' => $targetUser->id]);
    $otherUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $otherUser->id]);

    $event = EventBooking::factory()->create(['customer_id' => $customer->id]);
    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $event->id,
        'booking_type' => 'event_booking',
        'worker_id' => $targetWorker->id,
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($otherUser);
    $this->getJson("/api/v1/cleaning-time-warnings/{$warning->id}")->assertNotFound();
    expect($this->getJson('/api/v1/cleaning-time-warnings')->assertOk()->json('data'))->toBe([]);

    Sanctum::actingAs($targetUser);
    $this->getJson("/api/v1/cleaning-time-warnings/{$warning->id}")->assertOk()
        ->assertJsonPath('data.booking.id', $event->id);

    Sanctum::actingAs($customer);
    expect(array_column($this->getJson('/api/v1/cleaning-time-warnings')->assertOk()->json('data'), 'id'))
        ->toBe([$warning->id]);
});

it('allows staff with an explicit administrative role to read warnings including worker users', function (): void {
    $admin = User::factory()->create();
    Worker::factory()->create(['user_id' => $admin->id]);
    Role::findOrCreate('admin', 'web');
    $admin->assignRole('admin');

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $this->billingPolicy->id,
    ]);
    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'sent_at' => now(),
        'worker_responded_at' => now(),
    ]);

    Sanctum::actingAs($admin);
    expect(array_column($this->getJson('/api/v1/cleaning-time-warnings')->assertOk()->json('data'), 'id'))
        ->toBe([$warning->id]);
    $this->getJson("/api/v1/cleaning-time-warnings/{$warning->id}")->assertOk();
});
