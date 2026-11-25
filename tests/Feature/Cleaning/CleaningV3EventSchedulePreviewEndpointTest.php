<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningEventType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

uses(RefreshDatabase::class);

function v3EventScheduleFixture(array $tariffs = [], bool $required = false): array
{
    $event = CleaningEventType::query()->create([
        'name' => 'QA event', 'slug' => 'qa-v3-long-event', 'is_active' => true,
    ]);
    $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'QA event catalog']);
    $item = $catalog->items()->create([
        'item_key' => 'event.qa', 'source_type' => 'event_type', 'source_id' => $event->id,
        'label_ar' => 'QA event', 'measurement_mode' => 'hour', 'is_enabled' => true,
        'required' => $required,
    ]);
    foreach ($tariffs as $tariff) {
        $item->tariffRules()->create($tariff);
    }
    $digest = str_repeat('c', 64);
    DB::table('cleaning_v3_catalog_versions')->where('id', $catalog->id)->update([
        'status' => 'published', 'published_at' => now(), 'published_digest' => $digest,
    ]);
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', $digest);

    return [
        'appointmentKey' => 'event-qa', 'catalogVersionKey' => $catalog->version_key,
        'catalogRevision' => $digest, 'eventItemKey' => 'event.qa',
        'startDate' => now('Asia/Damascus')->addDay()->format('Y-m-d'),
        'startTime' => '08:00', 'durationMinutes' => 720, 'requiredWorkers' => 2,
    ];
}

test('V3 long event API splits twelve hours into two distinct crew visits with no financial writes', function (): void {
    $input = v3EventScheduleFixture();
    $url = '/api/v1/cleaning/v3/event-schedule/preview';
    $this->postJson($url, $input)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $tables = ['cleaning_bookings', 'cleaning_booking_sessions', 'cleaning_booking_quotes', 'cleaning_visit_funding_reservations'];
    $before = array_map(static fn ($table) => DB::table($table)->count(), $tables);
    $response = $this->postJson($url, $input)->assertOk()
        ->assertJsonPath('data.previewOnly', true)
        ->assertJsonPath('data.canQuote', false)
        ->assertJsonPath('data.canCreateBooking', false)
        ->assertJsonPath('data.quoteId', null)
        ->assertJsonPath('data.event.distinctCrewsRequired', true)
        ->assertJsonPath('data.event.minimumDistinctWorkers', 4)
        ->assertJsonPath('data.additionalWorkerFeePreview.totalEstimatedFeeSyp', '300000.00')
        ->assertJsonPath('data.additionalWorkerFeePreview.visits.0.estimatedFeeSyp', '150000.00')
        ->assertJsonPath('data.additionalWorkerFeePreview.visits.1.estimatedFeeSyp', '150000.00')
        ->assertJsonPath('data.additionalWorkerFeePreview.canCharge', false)
        ->assertJsonPath('data.summary.appointmentCount', 1)
        ->assertJsonPath('data.summary.visitCount', 2)
        ->assertJsonPath('data.summary.totalActiveMinutes', 720)
        ->assertJsonPath('data.appointments.0.visits.0.durationMinutes', 480)
        ->assertJsonPath('data.appointments.0.visits.1.durationMinutes', 240)
        ->assertJsonPath('data.appointments.0.visits.1.scheduledTime', '16:00')
        ->assertJsonPath('data.appointments.0.visits.0.visitId', null)
        ->assertJsonPath('data.appointments.0.visits.1.visitId', null);
    $again = $this->postJson($url, $input)->assertOk();
    expect($again->json('data.draftFingerprint'))->toBe($response->json('data.draftFingerprint'))
        ->and(array_map(static fn ($table) => DB::table($table)->count(), $tables))->toBe($before);
});

test('V3 event preview rejects past time, invalid step, unknown event, stale revision and cross-midnight', function (): void {
    $input = v3EventScheduleFixture();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $url = '/api/v1/cleaning/v3/event-schedule/preview';
    foreach ([
        ['startDate', '2000-01-01', 'V3_EVENT_START_INVALID'],
        ['durationMinutes', 75, 'INVALID_EVENT_DURATION_STEP'],
        ['eventItemKey', 'event.missing', 'V3_EVENT_ITEM_UNAVAILABLE'],
        ['startTime', '15:00', 'V3_EVENT_CROSS_MIDNIGHT'],
        ['price', '1.00', 'INVALID_V3_EVENT_SCHEDULE'],
    ] as [$field, $value, $code]) {
        $this->postJson($url, [...$input, $field => $value])->assertStatus(422)->assertJsonPath('code', $code);
    }
    $this->postJson($url, [...$input, 'catalogRevision' => str_repeat('b', 64)])->assertStatus(409);
    $this->postJson($url, [...$input, 'durationMinutes' => 510])->assertOk()
        ->assertJsonPath('data.appointments.0.visits.0.durationMinutes', 450)
        ->assertJsonPath('data.appointments.0.visits.1.durationMinutes', 60);
    config()->set('cleaning_v3_catalog.read_enabled', false);
    $this->postJson($url, $input)->assertStatus(503);
});

test('commercial event review preserves server segments and separate crew fees', function (): void {
    $input = v3EventScheduleFixture();
    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $address = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $customer->id, 'street' => 'QA street', 'latitude' => '36.20', 'longitude' => '37.15',
    ]);
    $revision = $this->getJson('/api/v1/cleaning/v3/customer-preferences')->assertOk()->json('data.addresses.0.revision');
    DB::table('cleaning_financial_settings')->delete();
    \App\Models\CleaningFinancialSetting::query()->create([
        ...\Modules\Cleaning\Support\CleaningRuntimeSettings::financialDefaults(),
        'travel_markup_value' => 1000, 'cleaning_minimum_order_price' => 999999,
        'supervisor_hourly_rate' => 5000, 'extension_rate_per_30_minutes' => 10000,
    ]);
    $schedule = $this->postJson('/api/v1/cleaning/v3/event-schedule/preview', $input)->assertOk();
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $input['catalogVersionKey'],
        'catalogRevision' => $input['catalogRevision'], 'answers' => [], 'eventRule' => $input,
        'pricingPreviewRequested' => true,
        'preferences' => ['addressId' => $address->id, 'addressRevision' => $revision,
            'genderPreference' => 'any', 'excludedWorkerIds' => []],
        'appointments' => array_map(fn ($a) => ['appointmentKey' => $a['appointmentKey'],
            'visits' => array_map(fn ($v) => array_intersect_key($v, array_flip([
                'occurrenceKey', 'scheduledDate', 'scheduledTime', 'durationMinutes', 'requiredWorkers',
            ])), $a['visits'])], $schedule->json('data.appointments')),
    ];
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $this->postJson($url, $form)->assertOk()
        ->assertJsonPath('data.event.minimumDistinctWorkers', 4)
        ->assertJsonPath('data.commercialPricePreview.visits.0.baseAmountSyp', '320000.00')
        ->assertJsonPath('data.commercialPricePreview.visits.1.baseAmountSyp', '160000.00')
        ->assertJsonPath('data.commercialPricePreview.totals.travelAmountSyp', '4000.00')
        ->assertJsonPath('data.commercialPricePreview.totals.additionalWorkerFeeSyp', '300000.00')
        ->assertJsonPath('data.commercialPricePreview.totals.minimumTopUpSyp', '0.00')
        ->assertJsonPath('data.commercialPricePreview.totals.supervisorAmountSyp', '0.00')
        ->assertJsonPath('data.commercialPricePreview.totals.totalAmountSyp', '784000.00')
        ->assertJsonPath('data.quoteId', null);
    $tampered = $form;
    $tampered['appointments'][0]['visits'][1]['scheduledTime'] = '16:30';
    $this->postJson($url, $tampered)->assertStatus(422)->assertJsonPath('code', 'V3_EVENT_PLAN_MISMATCH');
    $this->postJson($url, [...$form, 'answers' => ['event.qa' => 12]])->assertStatus(422);
});

function v3SpecialEventCommercialForm(array $input): array
{
    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $address = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $customer->id, 'street' => 'QA street', 'latitude' => '36.20', 'longitude' => '37.15',
    ]);
    $revision = test()->getJson('/api/v1/cleaning/v3/customer-preferences')->assertOk()->json('data.addresses.0.revision');
    DB::table('cleaning_financial_settings')->delete();
    \App\Models\CleaningFinancialSetting::query()->create([
        ...\Modules\Cleaning\Support\CleaningRuntimeSettings::financialDefaults(),
        'travel_markup_value' => 1000, 'cleaning_minimum_order_price' => 999999,
        'supervisor_hourly_rate' => 5000, 'extension_rate_per_30_minutes' => 10000,
    ]);
    $schedule = test()->postJson('/api/v1/cleaning/v3/event-schedule/preview', $input)->assertOk();
    return [
        'contractVersion' => 3, 'catalogVersionKey' => $input['catalogVersionKey'],
        'catalogRevision' => $input['catalogRevision'], 'answers' => [], 'eventRule' => $input,
        'pricingPreviewRequested' => true,
        'preferences' => ['addressId' => $address->id, 'addressRevision' => $revision,
            'genderPreference' => 'any', 'excludedWorkerIds' => []],
        'appointments' => array_map(fn ($a) => ['appointmentKey' => $a['appointmentKey'],
            'visits' => array_map(fn ($v) => array_intersect_key($v, array_flip([
                'occurrenceKey', 'scheduledDate', 'scheduledTime', 'durationMinutes', 'requiredWorkers',
            ])), $a['visits'])], $schedule->json('data.appointments')),
    ];
}

function v3EventTariff(string $key, string $min, string $max, string $price, array $overrides = []): array
{
    return array_replace([
        'rule_key' => $key, 'cleaning_mode' => 'regular', 'scope_key' => 'whole',
        'quantity_min' => $min, 'quantity_max' => $max,
        'unit_price' => $price, 'unit_effort_minutes' => '60.00', 'is_enabled' => true,
    ], $overrides);
}

test('special event rate selects the tier for total worker hours and conserves money across crews', function (): void {
    $form = v3SpecialEventCommercialForm(v3EventScheduleFixture([
        v3EventTariff('short', '0', '20', '30000.00'),
        v3EventTariff('long', '20', '30', '12500.01'),
    ], true));
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $result = $this->postJson($url, $form)->assertOk()
        ->assertJsonPath('data.commercialPricePreview.visits.0.baseAmountSyp', '200000.16')
        ->assertJsonPath('data.commercialPricePreview.visits.1.baseAmountSyp', '100000.08')
        ->assertJsonPath('data.commercialPricePreview.totals.baseAmountSyp', '300000.24')
        ->assertJsonPath('data.commercialPricePreview.totals.travelAmountSyp', '4000.00')
        ->assertJsonPath('data.commercialPricePreview.totals.additionalWorkerFeeSyp', '300000.00')
        ->assertJsonPath('data.commercialPricePreview.totals.totalAmountSyp', '604000.24')
        ->assertJsonPath('data.commercialPricePreview.canQuote', false)
        ->assertJsonPath('data.quoteId', null);
    $hash = $result->json('data.commercialPricePreview.pricingFingerprint');
    $this->postJson($url, [...$form, 'expectedPricingFingerprint' => $hash])->assertOk()
        ->assertJsonPath('data.commercialPricePreview.pricingFingerprint', $hash);

    // The required event item is satisfied by the canonical event rule.
    // Never accept a second user-supplied hours value.
    $this->postJson($url, [...$form, 'answers' => ['event.qa' => 24]])->assertStatus(422)
        ->assertJsonPath('code', 'V3_EVENT_SCHEDULE_REQUIRED');
    $this->postJson('/api/v1/cleaning/v3/workload/preview', [
        'catalogVersionKey' => $form['catalogVersionKey'], 'catalogRevision' => $form['catalogRevision'],
        'cleaningMode' => 'regular', 'answers' => ['event.qa' => 24],
    ])->assertStatus(422)->assertJsonPath('code', 'V3_EVENT_SCHEDULE_REQUIRED');

    // Special rate must not depend on the legacy fallback.
    DB::table('cleaning_financial_settings')->update(['extension_rate_per_30_minutes' => 0]);
    $this->postJson($url, [...$form, 'expectedPricingFingerprint' => $hash])->assertOk();
    expect(DB::table('cleaning_booking_quotes')->count())->toBe(0)
        ->and(DB::table('cleaning_visit_funding_reservations')->count())->toBe(0);
});

test('half hour event split rounds the full published price once', function (): void {
    $input = v3EventScheduleFixture([v3EventTariff('fractional', '0', '20', '10.01')]);
    $input['durationMinutes'] = 510;
    $input['requiredWorkers'] = 1;
    $form = v3SpecialEventCommercialForm($input);
    $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)->assertOk()
        ->assertJsonPath('data.commercialPricePreview.visits.0.baseAmountSyp', '75.08')
        ->assertJsonPath('data.commercialPricePreview.visits.1.baseAmountSyp', '10.01')
        ->assertJsonPath('data.commercialPricePreview.totals.baseAmountSyp', '85.09')
        ->assertJsonPath('data.commercialPricePreview.totals.additionalWorkerFeeSyp', '0.00')
        ->assertJsonPath('data.commercialPricePreview.totals.totalAmountSyp', '2085.09');
});

test('invalid special event tariff never silently falls back to legacy pricing', function (array $rules, string $code): void {
    $form = v3SpecialEventCommercialForm(v3EventScheduleFixture($rules));
    $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)->assertStatus(422)
        ->assertJsonPath('code', $code);
})->with([
    'exclusive upper bound' => [[v3EventTariff('short', '0', '24', '10000')], 'V3_PUBLISHED_TARIFF_COVERAGE_MISSING'],
    'overlap' => [[v3EventTariff('a', '0', '30', '10000'), v3EventTariff('b', '20', '40', '12000')], 'V3_PUBLISHED_TARIFF_AMBIGUOUS'],
    'disabled only' => [[v3EventTariff('disabled', '0', '40', '10000', ['is_enabled' => false])], 'V3_PUBLISHED_TARIFF_COVERAGE_MISSING'],
    'inconsistent effort' => [[v3EventTariff('bad-effort', '0', '40', '10000', ['unit_effort_minutes' => '30.00'])], 'V3_COMMERCIAL_EVENT_TARIFF_UNSUPPORTED'],
    'wrong scope' => [[v3EventTariff('bad-scope', '0', '40', '10000', ['scope_key' => 'floor'])], 'V3_COMMERCIAL_EVENT_TARIFF_UNSUPPORTED'],
]);
