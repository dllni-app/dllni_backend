<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningCouponPricingService;
use Modules\Cleaning\Support\CleaningFinancialDefaults;
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
            'calculationMode' => 'task',
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

it('persists the quoted transport for every multi-worker recurring visit', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'commission_type' => 'percent',
        'default_commission_rate' => 10,
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 75,
        'cleaning_minimum_order_price' => 0,
    ]);

    $payload = recurringCleaningPayload();
    $payload['numberOfWorkers'] = 2;

    $estimate = postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)->assertOk();
    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $created->json('order.id'));
    $sessions = $booking->sessions()->get();

    expect((float) $estimate->json('schedule.sessions.0.travelFee'))->toBe(150.0)
        ->and($sessions)->toHaveCount(3)
        ->and($sessions->map(fn (CleaningBookingSession $session): float => (float) $session->travel_fee)->all())
        ->toBe([150.0, 150.0, 150.0])
        ->and((float) $booking->total_price)->toBe((float) $estimate->json('pricing.totalPrice'));
});

it('does not add commission on top of the minimum for recurring visits', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'commission_type' => 'percent',
        'default_commission_rate' => 25,
        'travel_markup_type' => 'none',
        'travel_markup_value' => 0,
        'cleaning_base_unit_price' => 50,
        'cleaning_minimum_order_price' => 150,
        'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(),
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
    ]);

    $payload = recurringCleaningPayload();
    $payload['propertyDetails']['room_size_breakdown'] = ['kitchen' => ['small' => 1]];

    $estimate = postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)->assertOk();
    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $created->json('order.id'));
    $sessions = $booking->sessions()->get();

    expect((float) $estimate->json('schedule.sessions.0.totalPrice'))->toBe(150.0)
        ->and((float) $estimate->json('pricing.totalPrice'))->toBe(450.0)
        ->and($sessions->map(fn (CleaningBookingSession $session): float => (float) $session->total_price)->all())
        ->toBe([150.0, 150.0, 150.0])
        ->and((float) $booking->total_price)->toBe(450.0);
});

it('discounts the actual recurring minimum-inclusive total without increasing its subtotal', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'commission_type' => 'percent',
        'default_commission_rate' => 25,
        'travel_markup_type' => 'none',
        'travel_markup_value' => 0,
        'cleaning_base_unit_price' => 50,
        'cleaning_minimum_order_price' => 150,
        'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(),
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
    ]);

    PlatformCoupon::query()->create([
        'code' => 'RECURRING10',
        'title_ar' => 'خصم الزيارات',
        'title_en' => 'Recurring discount',
        'description_ar' => 'خصم على الحجز الدوري',
        'description_en' => 'Recurring booking discount',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 10,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'is_active' => true,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
    ]);

    $payload = recurringCleaningPayload();
    $payload['propertyDetails']['room_size_breakdown'] = ['kitchen' => ['small' => 1]];
    $estimate = postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)->assertOk();

    $payload['couponCode'] = 'RECURRING10';
    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $created->json('order.id'));
    $couponAllocation = app(CleaningCouponPricingService::class)->storedBreakdown($booking);

    expect((float) $estimate->json('pricing.totalPrice'))->toBe(450.0)
        ->and((float) $booking->subtotal_before_discount)->toBe(450.0)
        ->and((float) $booking->discount_amount)->toBe(45.0)
        ->and((float) $booking->total_price)->toBe(405.0)
        ->and((float) $booking->sessions()->sum('total_price'))->toBe(450.0)
        ->and((float) $couponAllocation['adminDiscountAmount'])->toBe(45.0)
        ->and((float) $couponAllocation['workerDiscountAmount'])->toBe(0.0)
        ->and((float) $couponAllocation['totalPrice'])->toBe(405.0)
        ->and($booking->platform_coupon_code)->toBe('RECURRING10');
});

it('refreshes the urgent-order snapshot after canonicalizing recurring session dates', function (): void {
    $payload = recurringCleaningPayload();
    // The initial booking is for today, but its earliest recurring session is
    // two days later. The canonical parent must no longer be marked urgent.
    $payload['scheduledDate'] = now(config('app.timezone'))->toDateString();

    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $created->json('order.id'));
    $firstSession = $booking->sessions()->orderBy('sequence')->firstOrFail();

    expect($booking->scheduled_date?->toDateString())->toBe($firstSession->scheduled_date->toDateString())
        ->and((bool) data_get($booking->property_details, 'is_hot_order'))->toBeFalse()
        ->and(data_get($booking->property_details, 'hot_order_label'))->toBeNull()
        ->and(data_get($booking->property_details, 'hot_order_prefix'))->toBeNull()
        ->and((string) data_get($booking->property_details, 'order_title'))->not->toContain('طلب ساخن');
});

it('marks the recurring parent as urgent if its first canonical session is today', function (): void {
    $payload = recurringCleaningPayload();
    $payload['schedule']['sessions'][1] = [
        'date' => now(config('app.timezone'))->toDateString(),
        'time' => '23:59',
    ];

    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $created->json('order.id'));

    expect($booking->scheduled_date?->toDateString())->toBe(now(config('app.timezone'))->toDateString())
        ->and((bool) data_get($booking->property_details, 'is_hot_order'))->toBeTrue()
        ->and((string) data_get($booking->property_details, 'hot_order_label'))->toContain('طلب ساخن')
        ->and((string) data_get($booking->property_details, 'order_title'))->toContain('طلب ساخن');
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
            fn (CleaningBookingSession $session): bool => $session->calculation_mode === RecurringCleaningScheduleService::CALCULATION_TASK,
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

it('supports fixed-hour recurring visits with worker-seat pricing', function (): void {
    $payload = recurringCleaningPayload();
    $payload['numberOfWorkers'] = 2;
    $payload['schedule']['calculationMode'] = 'hours';
    $payload['schedule']['hoursPerVisit'] = 2.25;

    $estimate = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
        'assignmentMode' => $payload['assignmentMode'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'schedule' => $payload['schedule'],
    ])->assertOk();

    $estimate
        ->assertJsonPath('schedule.calculationMode', 'hours')
        ->assertJsonPath('schedule.hoursPerVisit', 2.5)
        ->assertJsonPath('schedule.sessions.0.hours', 2.5)
        ->assertJsonPath('pricing.recurringHoursPerVisit', 2.5)
        ->assertJsonPath('pricing.recurringWorkerCount', 2);

    expect((float) $estimate->json('size.estimatedHours'))->toBe(7.5)
        ->and((float) $estimate->json('pricing.recurringHourlyRatePerWorker'))->toBeGreaterThan(0)
        ->and((float) $estimate->json('schedule.sessions.0.basePrice'))->toBeGreaterThan(0);

    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $created->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $sessions = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->orderBy('sequence')
        ->get();

    expect($sessions)->toHaveCount(3)
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => $session->calculation_mode === 'hours'))->toBeTrue()
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => (float) $session->duration_hours === 2.5))->toBeTrue()
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => (string) data_get($session->pricing_snapshot, 'calculationMode') === 'hours'))->toBeTrue()
        ->and((float) $booking->total_hours)->toBe(7.5);
});

it('validates the recurring calculation-mode contract', function (): void {
    $payload = recurringCleaningPayload();
    $payload['schedule']['calculationMode'] = 'hours';
    unset($payload['schedule']['hoursPerVisit']);

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.hoursPerVisit');

    $payload = recurringCleaningPayload();
    $payload['schedule']['hoursPerVisit'] = 2;

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.hoursPerVisit');

    $payload = recurringCleaningPayload();
    $payload['schedule']['calculationMode'] = 'hours';
    $payload['schedule']['hoursPerVisit'] = 25;

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.hoursPerVisit');
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

it('accepts a monthly visit 31 days after the first scheduled visit', function (): void {
    // A legitimate monthly recurrence (Oct -> Nov) can span 31 days,
    // unlike a weekly recurrence which follows a seven-day cadence.
    $payload = recurringCleaningPayload();
    $anchor = Carbon\CarbonImmutable::parse('2026-10-14', config('app.timezone'));
    if ($anchor->isPast()) {
        $anchor = Carbon\CarbonImmutable::now(config('app.timezone'))
            ->addMonthsNoOverflow(2)
            ->startOfMonth()->setDay(14);
    }
    $next = $anchor->addMonthNoOverflow();
    $payload['scheduledDate'] = $anchor->toDateString();
    $payload['schedule']['calculationMode'] = 'hours';
    $payload['schedule']['hoursPerVisit'] = 2.0;
    $payload['schedule']['sessions'] = [
        ['date' => $anchor->toDateString(), 'time' => '09:00'],
        ['date' => $next->toDateString(), 'time' => '09:00'],
    ];

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
        'assignmentMode' => $payload['assignmentMode'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'schedule' => $payload['schedule'],
    ])->assertOk();
    $response->assertJsonPath('schedule.sessionsCount', 2);
});

it('rejects recurring visits that span more than ninety days', function (): void {
    $payload = recurringCleaningPayload();
    $payload['schedule']['sessions'] = [
        [
            'date' => now(config('app.timezone'))->addDays(2)->toDateString(),
            'time' => '10:00',
        ],
        [
            'date' => now(config('app.timezone'))->addDays(93)->toDateString(),
            'time' => '10:00',
        ],
    ];

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.sessions');

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
        'assignmentMode' => $payload['assignmentMode'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'schedule' => $payload['schedule'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.sessions');
});

it('stores an explicit multi-worker recurring specific scope without widening the pool', function (): void {
    $workers = Worker::factory()->count(2)->create();
    $payload = recurringCleaningPayload();
    unset($payload['assignmentMode'], $payload['numberOfWorkers']);
    $payload['workerScope'] = 'specific';
    $payload['preferredWorkerIds'] = $workers->pluck('id')->all();

    $estimate = postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)->assertOk();
    $estimate
        ->assertJsonPath('workerScope', 'specific')
        ->assertJsonPath('assignmentMode', CleaningAssignmentMode::OpenCount->value)
        ->assertJsonPath('workerAcceptance.required', 2);
    expect($estimate->json('specificWorkerIds'))->toBe($workers->pluck('id')->all());

    $create = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $create->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $sessions = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->orderBy('sequence')
        ->get();

    expect((string) $booking->worker_scope)->toBe(CleaningBooking::WORKER_SCOPE_SPECIFIC)
        ->and($booking->specificWorkerIds())->toBe($workers->pluck('id')->all())
        ->and($booking->resolvedAssignmentMode())->toBe(CleaningAssignmentMode::OpenCount->value)
        ->and($booking->preferred_worker_id)->toBeNull()
        ->and((int) $booking->number_of_workers)->toBe(2)
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => (int) $session->required_workers === 2))->toBeTrue()
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => data_get($session->pricing_snapshot, 'workerScope') === 'specific'))->toBeTrue()
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => data_get($session->pricing_snapshot, 'specificWorkerIds') === $workers->pluck('id')->all()))->toBeTrue();

    getJson("/api/v1/cleaning-bookings/{$bookingId}/schedule")
        ->assertOk()
        ->assertJsonPath('data.schedule.workerScope', 'specific')
        ->assertJsonPath('data.schedule.specificWorkerIds.0', (int) $workers[0]->id)
        ->assertJsonPath('data.schedule.specificWorkerIds.1', (int) $workers[1]->id);
});

it('canonicalizes explicit any-worker recurring scope and clears preferred ids', function (): void {
    $worker = Worker::factory()->create();
    $payload = recurringCleaningPayload();
    $payload['workerScope'] = 'any';
    $payload['preferredWorkerIds'] = [$worker->id];
    $payload['preferredWorkerId'] = $worker->id;
    $payload['assignmentMode'] = 'preferred_worker';

    $create = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $create->json('order.id'));

    expect((string) $booking->worker_scope)->toBe(CleaningBooking::WORKER_SCOPE_ANY)
        ->and($booking->specificWorkerIds())->toBe([])
        ->and($booking->preferred_worker_id)->toBeNull()
        ->and($booking->resolvedAssignmentMode())->toBe(CleaningAssignmentMode::OpenCount->value);
});

it('rejects workerScope outside a recurring cleaning schedule', function (): void {
    $worker = Worker::factory()->create();
    $payload = recurringCleaningPayload();
    unset($payload['schedule']);
    $payload['workerScope'] = 'specific';
    $payload['preferredWorkerIds'] = [$worker->id];

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workerScope');
});
