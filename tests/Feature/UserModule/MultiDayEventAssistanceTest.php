<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionStatusService;

beforeEach(function (): void {
    $this->seed(CleaningFinancialSettingsSeeder::class);

    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Multi-day event test cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ],
    );

    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Multi-day event test billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ],
    );
});

it('estimates a multi-day event per worker per session hour and returns a schedule breakdown', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $firstDate = now()->addDays(3)->toDateString();
    $secondDate = now()->addDays(4)->toDateString();

    $response = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'eventType' => 'birthday',
            'guestCount' => 20,
            'venueType' => 'apartment',
            'customService' => 'Event assistance',
            'hours' => 2,
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 3,
        'schedule' => [
            'mode' => 'multi_day',
            'sessions' => [
                ['date' => $firstDate, 'time' => '18:00', 'hours' => 2],
                ['date' => $secondDate, 'time' => '17:00', 'hours' => 3],
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('schedule.mode', 'multi_day')
        ->assertJsonPath('schedule.daysCount', 2)
        ->assertJsonPath('schedule.totalHours', 5)
        ->assertJsonPath('pricing.eventHourlyRate', 400)
        ->assertJsonPath('pricing.eventHours', 5)
        ->assertJsonPath('pricing.eventWorkerCount', 3)
        ->assertJsonPath('pricing.basePrice', 6000)
        ->assertJsonPath('schedule.sessions.0.basePrice', 2400)
        ->assertJsonPath('schedule.sessions.1.basePrice', 3600);
});

it('creates one parent booking with real session rows and aggregate legacy fields', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $firstDate = now()->addDays(5)->toDateString();
    $secondDate = now()->addDays(6)->toDateString();

    $response = $this->postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'eventType' => 'birthday',
            'guestCount' => 20,
            'venueType' => 'apartment',
            'customService' => 'Event assistance',
            'hours' => 2,
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 3,
        'schedule' => [
            'mode' => 'multi_day',
            'sessions' => [
                ['date' => $firstDate, 'time' => '18:00', 'hours' => 2],
                ['date' => $secondDate, 'time' => '17:00', 'hours' => 3],
            ],
        ],
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('order.schedule.mode', 'multi_day')
        ->assertJsonPath('order.schedule.daysCount', 2)
        ->assertJsonPath('order.schedule.totalHours', 5);

    $bookingId = (int) $response->json('order.id');
    $booking = CleaningBooking::query()->with('sessions')->findOrFail($bookingId);

    expect($booking->sessions)->toHaveCount(2)
        ->and($booking->scheduled_date?->toDateString())->toBe($firstDate)
        ->and((float) $booking->total_hours)->toBe(5.0)
        ->and((float) $booking->base_price)->toBe(6000.0)
        ->and((float) ($booking->property_details['hours'] ?? 0))->toBe(5.0);

    expect(DB::table('cleaning_booking_sessions')
        ->where('cleaning_booking_id', $bookingId)
        ->where('sequence', 2)
        ->whereDate('scheduled_date', $secondDate)
        ->where('duration_hours', 3)
        ->exists())->toBeTrue();
});

it('keeps the parent partially completed until the final required session is completed', function (): void {
    $booking = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Completed->value,
        'work_finished_at' => now(),
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 2,
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::WorkerAssigned->value,
    ]);

    $updated = app(CleaningBookingSessionStatusService::class)->refreshParent($booking->fresh(['sessions', 'workerAssignments']));

    expect($updated->status)->toBe(CleaningBookingStatus::PartiallyCompleted)
        ->and($updated->completedSessionsCount())->toBe(1)
        ->and($updated->remainingSessionsCount())->toBe(1);
});

it('finds a multi-day parent in scheduled-on queries from any child session date', function (): void {
    $booking = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'scheduled_date' => now()->addDays(7)->toDateString(),
        'scheduled_time' => '12:00',
    ]);
    $targetDate = now()->addDays(9)->toDateString();

    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'scheduled_date' => now()->addDays(7)->toDateString(),
        'scheduled_time' => '12:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Scheduled->value,
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 2,
        'scheduled_date' => $targetDate,
        'scheduled_time' => '12:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Scheduled->value,
    ]);

    expect(CleaningBooking::query()->scheduledOn($targetDate)->whereKey($booking->id)->exists())->toBeTrue();
});
