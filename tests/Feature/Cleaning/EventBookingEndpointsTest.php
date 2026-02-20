<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Enums\EventType;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\EventBooking;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists event bookings', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    EventBooking::factory()->count(3)->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->getJson('/api/v1/event-bookings');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates an event booking', function () {
    $customer = User::factory()->create(['email' => 'event-customer@example.com']);
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $payload = [
        'customerId' => $customer->id,
        'billingPolicyId' => $billingPolicy->id,
        'bookingNumber' => 'EVT-TEST-'.fake()->unique()->randomNumber(4),
        'status' => EventBookingStatus::Pending->value,
        'eventType' => EventType::Birthday->value,
        'guestCountMin' => 20,
        'guestCountMax' => 50,
        'scheduledDate' => now()->addDays(3)->format('Y-m-d'),
        'scheduledTime' => '14:00',
        'totalHours' => 6,
        'basePrice' => 200,
        'travelFee' => 25,
        'totalPrice' => 225,
        'termsAccepted' => true,
    ];

    $response = $this->postJson('/api/v1/event-bookings', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('event_bookings', [
        'customer_id' => $customer->id,
        'event_type' => EventType::Birthday->value,
    ]);
});

it('shows an event booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = EventBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->getJson("/api/v1/event-bookings/{$booking->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($booking->id);
});

it('updates an event booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = EventBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
        'status' => EventBookingStatus::Pending->value,
    ]);

    $response = $this->putJson("/api/v1/event-bookings/{$booking->id}", [
        'customerId' => $booking->customer_id,
        'billingPolicyId' => $billingPolicy->id,
        'bookingNumber' => $booking->booking_number,
        'status' => EventBookingStatus::Confirmed->value,
        'eventType' => $booking->event_type->value,
        'guestCountMin' => $booking->guest_count_min,
        'guestCountMax' => $booking->guest_count_max,
        'scheduledDate' => $booking->scheduled_date->format('Y-m-d'),
        'scheduledTime' => $booking->scheduled_time,
        'totalHours' => (float) $booking->total_hours,
        'basePrice' => (float) $booking->base_price,
        'travelFee' => (float) $booking->travel_fee,
        'totalPrice' => (float) $booking->total_price,
        'termsAccepted' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('event_bookings', [
        'id' => $booking->id,
        'status' => EventBookingStatus::Confirmed->value,
    ]);
});

it('deletes an event booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = EventBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->deleteJson("/api/v1/event-bookings/{$booking->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('event_bookings', ['id' => $booking->id]);
});
