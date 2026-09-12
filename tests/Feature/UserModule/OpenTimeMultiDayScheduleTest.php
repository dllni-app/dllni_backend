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

    CancellationPolicy::query()->create([
        'module' => 'cleaning',
        'name' => 'Open-Time multi-day cancellation',
        'description' => 'Test policy',
        'rules' => ['free_until_hours' => 24],
        'is_active' => true,
        'is_default' => true,
    ]);
    CleaningBillingPolicy::query()->create([
        'name' => 'Open-Time multi-day billing',
        'billing_mode' => CleaningBillingMode::ActualWorkingTime->value,
        'rules' => [
            'min_billable_minutes' => 60,
            'rounding_minutes' => 15,
            'hard_max_minutes' => 480,
            'warning_minutes' => 30,
            'extension_options' => [15, 30, 60],
        ],
        'is_active' => true,
        'is_default' => true,
    ]);

    Sanctum::actingAs(User::factory()->create());
});

function openTimeMultiDayPayload(): array
{
    $firstDate = now(config('app.timezone'))->addDays(2)->toDateString();
    $secondDate = now(config('app.timezone'))->addDays(4)->toDateString();

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
        'scheduledTime' => '09:30',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 2,
        'openTime' => [
            'workerCount' => 2,
            'expectedMaxMinutes' => 240,
            'sessions' => [
                ['date' => $secondDate, 'time' => '15:00', 'expectedMaxMinutes' => 180],
                ['date' => $firstDate, 'time' => '09:30', 'expectedMaxMinutes' => 240],
            ],
        ],
        'termsAccepted' => true,
    ];
}

it('estimates independent open-time days with aggregate preliminary pricing', function (): void {
    $payload = openTimeMultiDayPayload();

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)
        ->assertOk()
        ->assertJsonPath('schedule.mode', 'multi_day')
        ->assertJsonPath('schedule.scheduleType', CleaningBookingSession::TYPE_OPEN_TIME)
        ->assertJsonPath('schedule.isOpenTime', true)
        ->assertJsonPath('schedule.sessionsCount', 2)
        ->assertJsonPath('schedule.totalHours', 7)
        ->assertJsonPath('pricing.openTime.totalExpectedMinutes', 420);

    expect((float) $response->json('pricing.basePrice'))
        ->toBe(round((float) collect($response->json('schedule.sessions'))->sum('basePrice'), 2));
});

it('creates canonical open-time child sessions and exposes their meters', function (): void {
    $create = postJson('/api/v1/user/cleaning/orders', openTimeMultiDayPayload())->assertCreated();
    $bookingId = (int) $create->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $sessions = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->orderBy('sequence')
        ->get();

    expect($booking->booking_kind)->toBe('open_time')
        ->and($sessions)->toHaveCount(2)
        ->and($sessions->pluck('session_type')->all())->toBe(['open_time', 'open_time'])
        ->and($sessions->pluck('open_time_expected_max_minutes')->all())->toBe([240, 180])
        ->and((float) $booking->base_price)->toBe(round((float) $sessions->sum('base_price'), 2));

    getJson("/api/v1/cleaning-bookings/{$bookingId}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.isOpenTime', true)
        ->assertJsonPath('data.schedule.isMultiSession', true)
        ->assertJsonPath('data.schedule.sessions.0.openTime.expectedMaxMinutes', 240)
        ->assertJsonPath('data.schedule.sessions.1.openTime.expectedMaxMinutes', 180);

    getJson("/api/v1/user/cleaning/orders/{$bookingId}/sessions/{$sessions->first()->id}/open-time/meter")
        ->assertOk()
        ->assertJsonPath('data.openTime.sessionId', (int) $sessions->first()->id)
        ->assertJsonPath('data.openTime.expectedMaxMinutes', 240)
        ->assertJsonPath('data.openTime.isFinalized', false);
});

it('rejects mixing recurring schedule semantics with open-time sessions', function (): void {
    $payload = openTimeMultiDayPayload();
    $payload['schedule'] = [
        'mode' => 'recurring',
        'sessions' => [
            ['date' => $payload['scheduledDate'], 'time' => $payload['scheduledTime']],
            ['date' => now(config('app.timezone'))->addDays(9)->toDateString(), 'time' => '09:30'],
        ],
    ];

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['schedule']);
});
