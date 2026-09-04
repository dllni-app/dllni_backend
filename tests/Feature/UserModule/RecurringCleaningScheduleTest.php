<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\User\Services\RecurringCleaningScheduleService;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Recurring cleaning test cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ],
    );

    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Recurring cleaning test billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ],
    );

    Sanctum::actingAs(User::factory()->create());
});

function recurringCleaningPayload(): array
{
    $firstDate = now(config('app.timezone'))->addDays(2)->toDateString();
    $secondDate = now(config('app.timezone'))->addDays(9)->toDateString();
    $thirdDate = now(config('app.timezone'))->addDays(16)->toDateString();

    return [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => $firstDate,
        'scheduledTime' => '10:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 1,
        'schedule' => [
            'mode' => 'recurring',
            // Intentionally unsorted. The backend owns canonical occurrence order.
            'sessions' => [
                ['date' => $thirdDate, 'time' => '12:00'],
                ['date' => $firstDate, 'time' => '10:00'],
                ['date' => $secondDate, 'time' => '18:00'],
            ],
        ],
        'termsAccepted' => true,
    ];
}

it('estimates recurring cleaning as repeated independent visits', function (): void {
    $payload = recurringCleaningPayload();

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
        'assignmentMode' => $payload['assignmentMode'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'schedule' => $payload['schedule'],
    ])->assertOk();

    $response
        ->assertJsonPath('schedule.mode', 'multi_day')
        ->assertJsonPath('schedule.scheduleType', RecurringCleaningScheduleService::SESSION_TYPE)
        ->assertJsonPath('schedule.isRecurring', true)
        ->assertJsonPath('schedule.sessionsCount', 3)
        ->assertJsonPath('schedule.daysCount', 3)
        ->assertJsonPath('pricing.recurringOccurrences', 3);

    $sessions = collect($response->json('schedule.sessions'));
    $sessionHours = (float) $sessions->first()['hours'];

    expect((float) $response->json('size.estimatedHours'))->toBe(round($sessionHours * 3, 2))
        ->and((float) $response->json('schedule.totalHours'))->toBe(round($sessionHours * 3, 2))
        ->and((float) $response->json('pricing.basePrice'))->toBe(round((float) $sessions->sum('basePrice'), 2))
        ->and((float) $response->json('pricing.totalPrice'))->toBe(round((float) $sessions->sum('totalPrice'), 2));
});

it('creates one parent cleaning booking with canonical recurring child sessions', function (): void {
    $payload = recurringCleaningPayload();

    $create = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $create->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $sessions = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->orderBy('sequence')
        ->get();

    expect($sessions)->toHaveCount(3)
        ->and($sessions->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($sessions->every(
            fn (CleaningBookingSession $session): bool => $session->session_type === RecurringCleaningScheduleService::SESSION_TYPE,
        ))->toBeTrue()
        ->and($sessions->every(
            fn (CleaningBookingSession $session): bool => $session->calculation_mode === 'estimated_hours',
        ))->toBeTrue()
        ->and($sessions->map(
            fn (CleaningBookingSession $session): string => $session->scheduled_date->toDateString(),
        )->all())->toBe([
            now(config('app.timezone'))->addDays(2)->toDateString(),
            now(config('app.timezone'))->addDays(9)->toDateString(),
            now(config('app.timezone'))->addDays(16)->toDateString(),
        ])
        ->and($booking->scheduled_date?->toDateString())->toBe($sessions->first()->scheduled_date->toDateString())
        ->and((string) $booking->scheduled_time)->toBe((string) $sessions->first()->scheduled_time)
        ->and((float) $booking->estimated_hours)->toBe(round((float) $sessions->sum('duration_hours'), 2))
        ->and((float) $booking->total_hours)->toBe(round((float) $sessions->sum('duration_hours'), 2))
        ->and((float) $booking->base_price)->toBe(round((float) $sessions->sum('base_price'), 2))
        ->and((float) $booking->total_price)->toBe(round((float) $sessions->sum('total_price'), 2));

    getJson("/api/v1/cleaning-bookings/{$bookingId}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.mode', 'multi_day')
        ->assertJsonPath('data.schedule.isMultiSession', true)
        ->assertJsonPath('data.schedule.sessionsCount', 3)
        ->assertJsonPath('data.schedule.sessions.0.sessionType', RecurringCleaningScheduleService::SESSION_TYPE)
        ->assertJsonPath('data.schedule.sessions.2.sessionType', RecurringCleaningScheduleService::SESSION_TYPE);
});

it('keeps legacy single cleaning unchanged when no recurring schedule is supplied', function (): void {
    $payload = recurringCleaningPayload();
    unset($payload['schedule']);

    $create = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $create->json('order.id');

    expect(CleaningBookingSession::query()->where('cleaning_booking_id', $bookingId)->count())->toBe(0);
});

it('requires at least two recurring visits and rejects client supplied visit hours', function (): void {
    $payload = recurringCleaningPayload();
    $payload['schedule']['sessions'] = [[
        'date' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'time' => '10:00',
        'hours' => 2,
    ]];

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'schedule.sessions',
            'schedule.sessions.0.hours',
        ]);
});

it('rejects duplicate recurring execution slots', function (): void {
    $payload = recurringCleaningPayload();
    $payload['schedule']['sessions'][2] = $payload['schedule']['sessions'][1];

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.sessions.2.time');
});
