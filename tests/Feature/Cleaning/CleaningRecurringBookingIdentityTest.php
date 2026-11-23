<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningRecurringBookingIdentityService;

uses(RefreshDatabase::class);

test('one V2 appointment is not misidentified as recurring', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $service = app(CleaningRecurringBookingIdentityService::class);

    expect($service->isRecurringOrMultiVisit($booking))->toBeFalse();

    $quoteId = (string) Str::uuid();
    DB::table('cleaning_booking_quotes')->insert([
        'id' => $quoteId,
        'customer_id' => $customer->id,
        'request_hash' => str_repeat('a', 64),
        'signature' => str_repeat('b', 64),
        'snapshot' => json_encode(['appointments' => []], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('cleaning_quote_bookings')->insert([
        'quote_id' => $quoteId,
        'cleaning_booking_id' => $booking->id,
        'appointment_sequence' => 1,
    ]);

    expect($service->isRecurringOrMultiVisit($booking))->toBeFalse();
});

test('two distinct historic V2 bookings sharing one quote count as one multi-visit schedule', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $first = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $second = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $other = CleaningBooking::factory()->create(['customer_id' => $customer->id]);

    $quoteId = (string) Str::uuid();
    DB::table('cleaning_booking_quotes')->insert([
        'id' => $quoteId,
        'customer_id' => $customer->id,
        'request_hash' => str_repeat('a', 64),
        'signature' => str_repeat('b', 64),
        'snapshot' => json_encode(['appointments' => []], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('cleaning_quote_bookings')->insert([
        ['quote_id' => $quoteId, 'cleaning_booking_id' => $first->id, 'appointment_sequence' => 1],
        ['quote_id' => $quoteId, 'cleaning_booking_id' => $second->id, 'appointment_sequence' => 2],
    ]);

    $service = app(CleaningRecurringBookingIdentityService::class);
    expect($service->isRecurringOrMultiVisit($first))->toBeTrue()
        ->and($service->isRecurringOrMultiVisit($second))->toBeTrue()
        ->and($service->isRecurringOrMultiVisit($other))->toBeFalse();

    Sanctum::actingAs($customer);
    $this->getJson('/api/v1/user/cleaning/orders/'.$first->id)->assertOk()
        ->assertJsonPath('data.isRecurringOrMultiVisit', true);
    $this->getJson('/api/v1/user/cleaning/orders/'.$other->id)->assertOk()
        ->assertJsonPath('data.isRecurringOrMultiVisit', false);
});

test('V3 uses actual number of visits and does not identify unrelated V2 quotes', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $booking->forceFill(['contract_version' => 'v3'])->save();

    $service = app(CleaningRecurringBookingIdentityService::class);
    expect($service->isRecurringOrMultiVisit($booking))->toBeFalse();

    foreach ([1, 2] as $seq) {
        $booking->sessions()->create([
            'sequence' => $seq,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => now()->addDays($seq)->toDateString(),
            'scheduled_time' => '10:00',
            'duration_hours' => 2,
            'required_workers' => 1,
            'coverage_status' => 'searching',
            'status' => 'scheduled',
        ]);
        expect($service->isRecurringOrMultiVisit($booking))->toBe($seq > 1);
    }
});

test('recurring bookings list paginates only multi-visit orders for the authenticated customer', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $outsider = User::factory()->create(['is_active' => true]);
    $quoteId = (string) Str::uuid();

    $first = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $second = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $otherCustomer = CleaningBooking::factory()->create(['customer_id' => $outsider->id]);

    DB::table('cleaning_booking_quotes')->insert([
        'id' => $quoteId,
        'customer_id' => $customer->id,
        'request_hash' => str_repeat('a', 64),
        'signature' => str_repeat('b', 64),
        'snapshot' => json_encode(['appointments' => []], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('cleaning_quote_bookings')->insert([
        ['quote_id' => $quoteId, 'cleaning_booking_id' => $first->id, 'appointment_sequence' => 1],
        ['quote_id' => $quoteId, 'cleaning_booking_id' => $second->id, 'appointment_sequence' => 2],
    ]);

    Sanctum::actingAs($customer);
    $url = '/api/v1/user/cleaning/orders?recurringOnly=1&perPage=1';

    $pageOne = $this->getJson($url)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.isRecurringOrMultiVisit', true);
    $pageTwo = $this->getJson($url.'&page=2')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.isRecurringOrMultiVisit', true);

    expect([
        $pageOne->json('data.0.id'),
        $pageTwo->json('data.0.id'),
    ])->toEqualCanonicalizing([$first->id, $second->id]);

    Sanctum::actingAs($outsider);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertDontSee((string) $first->booking_number);
});

test('V3 recurrence server-side query requires more than one visit, with V2 one-offs excluded', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $multi = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $multi->forceFill(['contract_version' => 'v3'])->save();
    $single = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $single->forceFill(['contract_version' => 'v3'])->save();

    foreach ([$multi, $single] as $booking) {
        $booking->sessions()->create([
            'sequence' => 1,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '10:00',
            'duration_hours' => 2,
            'required_workers' => 1,
            'coverage_status' => 'searching',
            'status' => 'scheduled',
        ]);
    }
    $multi->sessions()->create([
        'sequence' => 2,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);

    Sanctum::actingAs($customer);
    $this->getJson('/api/v1/user/cleaning/orders?recurringOnly=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $multi->id)
        ->assertJsonPath('data.0.isRecurringOrMultiVisit', true);
});

test('historical V1 schedules marked recurring are included without a V2 quote group', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $booking->forceFill(['property_details' => ['schedule' => ['mode' => 'recurring']]])->save();

    expect(app(CleaningRecurringBookingIdentityService::class)
        ->isRecurringOrMultiVisit($booking->fresh()))->toBeTrue();

    Sanctum::actingAs($customer);
    $this->getJson('/api/v1/user/cleaning/orders?recurringOnly=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $booking->id)
        ->assertJsonPath('data.0.isRecurringOrMultiVisit', true);
});
