<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists cleaning bookings', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    CleaningBooking::factory()->count(3)->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates a cleaning booking', function () {
    $customer = User::factory()->create(['email' => 'customer@example.com']);
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
        'bookingNumber' => 'CLN-'.mb_strtoupper(Str::random(6)),
        'status' => CleaningBookingStatus::Pending->value,
        'propertyType' => 'apartment',
        'scheduledDate' => now()->addDays(2)->format('Y-m-d'),
        'scheduledTime' => '10:00',
        'totalHours' => 3,
        'basePrice' => 80,
        'travelFee' => 10,
        'totalPrice' => 90,
        'termsAccepted' => true,
    ];

    $response = $this->postJson('/api/v1/cleaning-bookings', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('cleaning_bookings', [
        'booking_number' => $payload['bookingNumber'],
        'customer_id' => $customer->id,
    ]);
});

it('shows a cleaning booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($booking->id);
    expect($response->json('data.bookingNumber'))->toBe($booking->booking_number);
});

it('updates a cleaning booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    $response = $this->putJson("/api/v1/cleaning-bookings/{$booking->id}", [
        'customerId' => $booking->customer_id,
        'billingPolicyId' => $billingPolicy->id,
        'bookingNumber' => $booking->booking_number,
        'status' => CleaningBookingStatus::Confirmed->value,
        'propertyType' => $booking->property_type,
        'scheduledDate' => $booking->scheduled_date->format('Y-m-d'),
        'scheduledTime' => $booking->scheduled_time,
        'totalHours' => (float) $booking->total_hours,
        'basePrice' => (float) $booking->base_price,
        'travelFee' => (float) $booking->travel_fee,
        'totalPrice' => (float) $booking->total_price,
        'termsAccepted' => true,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Confirmed->value,
    ]);
});

it('deletes a cleaning booking', function () {
    $billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $billingPolicy->id,
    ]);

    $response = $this->deleteJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('cleaning_bookings', ['id' => $booking->id]);
});
