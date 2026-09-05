<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Recurring revision cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ],
    );
    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Recurring revision billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ],
    );
});

function recurringRevisionPayload(): array
{
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
        'scheduledDate' => now(config('app.timezone'))->addDays(2)->toDateString(),
        'scheduledTime' => '10:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 1,
        'schedule' => [
            'mode' => 'recurring',
            'sessions' => [
                ['date' => now(config('app.timezone'))->addDays(2)->toDateString(), 'time' => '10:00'],
                ['date' => now(config('app.timezone'))->addDays(9)->toDateString(), 'time' => '10:00'],
                ['date' => now(config('app.timezone'))->addDays(16)->toDateString(), 'time' => '10:00'],
            ],
        ],
        'termsAccepted' => true,
    ];
}

function createRecurringRevisionBooking(User $customer): CleaningBooking
{
    Sanctum::actingAs($customer);
    $response = postJson('/api/v1/user/cleaning/orders', recurringRevisionPayload())->assertCreated();

    return CleaningBooking::query()->findOrFail((int) $response->json('order.id'));
}

function revisionSchedule(array $days): array
{
    return [
        'mode' => 'recurring',
        'sessions' => array_map(
            static fn (int $day): array => [
                'date' => now(config('app.timezone'))->addDays($day)->toDateString(),
                'time' => '11:00',
            ],
            $days,
        ),
    ];
}

it('previews a recurring future schedule revision without mutating current visits', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $beforeDates = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('sequence')
        ->pluck('scheduled_date')
        ->map(fn ($date): string => Carbon\Carbon::parse($date)->toDateString())
        ->all();

    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => revisionSchedule([3, 10, 17])],
    )->assertOk();

    $preview
        ->assertJsonPath('data.revision.requiresReconfirmation', true)
        ->assertJsonPath('data.revision.scheduleChanged', true)
        ->assertJsonPath('data.revision.priceChanged', false)
        ->assertJsonPath('data.revision.editableSessionsCount', 3)
        ->assertJsonPath('data.revision.proposedSessionsCount', 3);
    expect((string) $preview->json('data.revision.revisionToken'))->toHaveLength(64)
        ->and(CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->orderBy('sequence')
            ->pluck('scheduled_date')
            ->map(fn ($date): string => Carbon\Carbon::parse($date)->toDateString())
            ->all())->toBe($beforeDates);
});

it('confirms an exact preview and keeps superseded visits as hidden audit history', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $schedule = revisionSchedule([3, 10, 17]);
    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk();
    $token = (string) $preview->json('data.revision.revisionToken');

    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        ['schedule' => $schedule, 'revisionToken' => $token],
    )
        ->assertOk()
        ->assertJsonPath('data.revision.applied', true)
        ->assertJsonPath('data.schedule.sessionsCount', 3)
        ->assertJsonPath('data.schedule.sessions.0.date', now(config('app.timezone'))->addDays(3)->toDateString())
        ->assertJsonPath('data.schedule.sessions.2.date', now(config('app.timezone'))->addDays(17)->toDateString());

    expect(CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->where('status', CleaningBookingSessionStatus::Superseded->value)
        ->count())->toBe(3)
        ->and(CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', CleaningBookingSessionStatus::Scheduled->value)
            ->count())->toBe(3);

    getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.sessionsCount', 3);
});

it('reprices a changed occurrence count and requires confirmation of the new total', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $schedule = revisionSchedule([3, 10, 17, 24]);

    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk();

    expect((bool) $preview->json('data.revision.priceChanged'))->toBeTrue()
        ->and((float) $preview->json('data.revision.newTotal'))->toBeGreaterThan((float) $preview->json('data.revision.oldTotal'));

    $token = (string) $preview->json('data.revision.revisionToken');
    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        ['schedule' => $schedule, 'revisionToken' => $token],
    )->assertOk()->assertJsonPath('data.schedule.sessionsCount', 4);

    expect((float) $booking->fresh()->total_price)->toBe((float) $preview->json('data.revision.newTotal'));
});

it('rejects stale recurring revision confirmation tokens', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $schedule = revisionSchedule([3, 10, 17]);
    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk();
    $token = (string) $preview->json('data.revision.revisionToken');

    $session = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('sequence')
        ->firstOrFail();
    $session->forceFill(['version' => (int) $session->version + 1])->save();

    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        ['schedule' => $schedule, 'revisionToken' => $token],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('revisionToken');
});

it('preserves historical sessions while replacing only editable future visits', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $first = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('sequence')
        ->firstOrFail();
    $first->forceFill([
        'status' => CleaningBookingSessionStatus::Completed,
        'work_started_at' => now()->subHours(2),
        'work_finished_at' => now()->subHour(),
    ])->save();
    $firstDate = $first->scheduled_date?->toDateString();

    $schedule = revisionSchedule([10, 17]);
    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk()->assertJsonPath('data.revision.preservedSessionsCount', 1);

    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        [
            'schedule' => $schedule,
            'revisionToken' => (string) $preview->json('data.revision.revisionToken'),
        ],
    )->assertOk()->assertJsonPath('data.schedule.sessionsCount', 3);

    $preserved = $first->fresh();
    expect($preserved?->status)->toBe(CleaningBookingSessionStatus::Completed)
        ->and($preserved?->scheduled_date?->toDateString())->toBe($firstDate);
});

it('releases accepted future workers when the customer confirms a schedule revision', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $worker = Worker::factory()->create();
    $session = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('sequence')
        ->firstOrFail();
    $assignment = CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now(),
        'service_share_amount' => 100,
        'travel_fee' => 0,
        'admin_margin_amount' => 10,
        'worker_amount' => 90,
        'currency' => 'SYP',
    ]);
    $session->forceFill(['status' => CleaningBookingSessionStatus::WorkerAssigned])->save();

    $schedule = revisionSchedule([3, 10, 17]);
    $preview = postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => $schedule],
    )->assertOk();
    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/confirm",
        [
            'schedule' => $schedule,
            'revisionToken' => (string) $preview->json('data.revision.revisionToken'),
        ],
    )->assertOk();

    $assignment->refresh();
    expect($assignment->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
        ->and($assignment->released_at)->not->toBeNull()
        ->and((string) $assignment->released_reason)->toContain('schedule revision');
});

it('blocks revisions while a recurring series is paused and enforces the thirty day future window', function (): void {
    $customer = User::factory()->create();
    $booking = createRecurringRevisionBooking($customer);
    $booking->forceFill([
        'recurring_paused_at' => now(),
        'recurring_pause_reason' => 'Away',
    ])->save();

    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => revisionSchedule([3, 10])],
    )->assertUnprocessable()->assertJsonValidationErrors('schedule');

    $booking->forceFill(['recurring_paused_at' => null, 'recurring_pause_reason' => null])->save();
    postJson(
        "/api/v1/user/cleaning/orders/{$booking->id}/recurring-schedule/preview",
        ['schedule' => revisionSchedule([2, 33])],
    )->assertUnprocessable()->assertJsonValidationErrors('schedule.sessions');
});

it('preserves hour-based pricing when future recurring visits are revised', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $payload = recurringRevisionPayload();
    $payload['schedule']['calculationMode'] = 'hours';
    $payload['schedule']['hoursPerVisit'] = 2.5;

    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $created->json('order.id');
    $schedule = [
        'mode' => 'recurring',
        'sessions' => [
            ['date' => now(config('app.timezone'))->addDays(3)->toDateString(), 'time' => '09:00'],
            ['date' => now(config('app.timezone'))->addDays(10)->toDateString(), 'time' => '09:00'],
        ],
    ];

    $preview = postJson("/api/v1/user/cleaning/orders/{$bookingId}/recurring-schedule/preview", [
        'schedule' => $schedule,
    ])->assertOk();

    $preview
        ->assertJsonPath('data.revision.calculationMode', 'hours')
        ->assertJsonPath('data.revision.hoursPerVisit', 2.5)
        ->assertJsonPath('data.revision.sessionHours', 2.5);

    $token = (string) $preview->json('data.revision.revisionToken');
    postJson("/api/v1/user/cleaning/orders/{$bookingId}/recurring-schedule/confirm", [
        'schedule' => $schedule,
        'revisionToken' => $token,
    ])->assertOk();

    $active = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->where('status', '!=', 'superseded')
        ->orderBy('sequence')
        ->get();

    expect($active)->toHaveCount(2)
        ->and($active->every(fn (CleaningBookingSession $session): bool => $session->calculation_mode === 'hours'))->toBeTrue()
        ->and($active->every(fn (CleaningBookingSession $session): bool => (float) $session->duration_hours === 2.5))->toBeTrue();
});
