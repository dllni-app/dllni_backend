<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Database\Seeders\CleaningFinancialSettingsSeeder;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

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

    Sanctum::actingAs(User::factory()->create());
});

function multiDayEventPayload(): array
{
    $firstDate = now(config('app.timezone'))->addDays(2)->toDateString();
    $secondDate = now(config('app.timezone'))->addDays(4)->toDateString();
    $thirdDate = now(config('app.timezone'))->addDays(6)->toDateString();

    return [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'location_name' => 'Event venue',
            'eventType' => 'birthday',
            'guestCount' => 30,
            'venueType' => 'villa',
            'customService' => 'Event assistance',
            // Legacy compatibility field: first execution session duration.
            'hours' => 2,
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 2,
        'scheduledDate' => $firstDate,
        'scheduledTime' => '10:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'schedule' => [
            'mode' => 'multi_day',
            // Intentionally unsorted to verify the backend owns canonical order.
            'sessions' => [
                ['date' => $thirdDate, 'time' => '12:00', 'hours' => 4],
                ['date' => $firstDate, 'time' => '10:00', 'hours' => 2],
                ['date' => $secondDate, 'time' => '18:00', 'hours' => 3],
            ],
        ],
        'termsAccepted' => true,
    ];
}

it('estimates a multi-day event from the aggregate session hours while exposing per-session pricing', function (): void {
    $payload = multiDayEventPayload();

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'assignmentMode' => $payload['assignmentMode'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
        'schedule' => $payload['schedule'],
    ])->assertOk();

    $response
        ->assertJsonPath('schedule.mode', 'multi_day')
        ->assertJsonPath('schedule.daysCount', 3)
        ->assertJsonPath('schedule.totalHours', 9)
        ->assertJsonPath('size.estimatedHours', 9)
        ->assertJsonPath('pricing.eventHours', 9)
        ->assertJsonPath('pricing.eventWorkerCount', 2)
        ->assertJsonPath('pricing.eventExecutionVisits', 3)
        ->assertJsonPath('schedule.sessions.0.hours', 2)
        ->assertJsonPath('schedule.sessions.1.hours', 3)
        ->assertJsonPath('schedule.sessions.2.hours', 4);

    $sessionBaseTotal = collect($response->json('schedule.sessions'))
        ->sum(fn (array $session): float => (float) $session['basePrice']);
    $sessionTravelTotal = collect($response->json('schedule.sessions'))
        ->sum(fn (array $session): float => (float) $session['travelFee']);
    $sessionTotal = collect($response->json('schedule.sessions'))
        ->sum(fn (array $session): float => (float) $session['totalPrice']);

    expect((float) $response->json('pricing.basePrice'))->toBe(round($sessionBaseTotal, 2))
        ->and((float) $response->json('pricing.travelFee'))->toBe(round($sessionTravelTotal, 2))
        ->and((float) $response->json('pricing.totalPrice'))->toBe(round($sessionTotal, 2));
});

it('creates one parent event and persists independently priced child sessions in canonical order', function (): void {
    $payload = multiDayEventPayload();

    $estimate = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'assignmentMode' => $payload['assignmentMode'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
        'schedule' => $payload['schedule'],
    ])->assertOk();

    $create = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $create->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $sessions = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->orderBy('sequence')
        ->get();

    expect($sessions)->toHaveCount(3)
        ->and((float) $booking->total_hours)->toBe(9.0)
        ->and((float) $booking->estimated_hours)->toBe(9.0)
        ->and((float) ($booking->property_details['hours'] ?? 0))->toBe(9.0)
        ->and($booking->scheduled_date?->toDateString())->toBe((string) $estimate->json('schedule.sessions.0.date'))
        ->and((string) $booking->scheduled_time)->toBe((string) $estimate->json('schedule.sessions.0.time'))
        ->and((float) $booking->base_price)->toBe((float) $estimate->json('pricing.basePrice'))
        ->and((float) $booking->travel_fee)->toBe((float) $estimate->json('pricing.travelFee'))
        ->and((float) $booking->total_price)->toBe((float) $estimate->json('pricing.totalPrice'));

    expect($sessions->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($sessions->map(fn (CleaningBookingSession $session): string => $session->scheduled_date->toDateString())->all())
        ->toBe(collect($estimate->json('schedule.sessions'))->pluck('date')->all())
        ->and($sessions->map(fn (CleaningBookingSession $session): float => (float) $session->duration_hours)->all())
        ->toBe([2.0, 3.0, 4.0])
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => (int) $session->required_workers === 2))->toBeTrue()
        ->and(round((float) $sessions->sum('base_price'), 2))->toBe((float) $booking->base_price)
        ->and(round((float) $sessions->sum('travel_fee'), 2))->toBe((float) $booking->travel_fee)
        ->and(round((float) $sessions->sum('total_price'), 2))->toBe((float) $booking->total_price);

    getJson("/api/v1/cleaning-bookings/{$bookingId}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.mode', 'multi_day')
        ->assertJsonPath('data.schedule.daysCount', 3)
        ->assertJsonPath('data.schedule.totalHours', 9)
        ->assertJsonPath('data.schedule.sessions.0.sequence', 1)
        ->assertJsonPath('data.schedule.sessions.2.sequence', 3);
});

it('keeps the legacy single-day event path unchanged when schedule is omitted', function (): void {
    $date = now(config('app.timezone'))->addDays(2)->toDateString();

    $create = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'eventType' => 'family_dinner',
            'guestCount' => 20,
            'venueType' => 'apartment',
            'customService' => 'Event assistance',
            'hours' => 2,
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 2,
        'scheduledDate' => $date,
        'scheduledTime' => '18:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ])->assertCreated();

    $bookingId = (int) $create->json('order.id');

    expect(CleaningBookingSession::query()->where('cleaning_booking_id', $bookingId)->count())->toBe(0);

    getJson("/api/v1/cleaning-bookings/{$bookingId}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.mode', 'single_day')
        ->assertJsonPath('data.schedule.daysCount', 1)
        ->assertJsonPath('data.schedule.sessions.0.date', $date)
        ->assertJsonPath('data.schedule.sessions.0.time', '18:00');
});

it('rejects duplicate event execution slots', function (): void {
    $payload = multiDayEventPayload();
    $duplicate = $payload['schedule']['sessions'][1];
    $payload['schedule']['sessions'][2] = [
        ...$duplicate,
        'hours' => 3,
    ];

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['schedule.sessions.2.time']);
});

it('rejects a schedule mode that does not match its session count', function (): void {
    $payload = multiDayEventPayload();
    $payload['schedule']['mode'] = 'single_day';

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'assignmentMode' => $payload['assignmentMode'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'schedule' => $payload['schedule'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['schedule.mode']);
});
