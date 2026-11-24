<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

uses(RefreshDatabase::class);

/** @return array{0:CleaningV3CatalogVersion,1:array<string,mixed>} */
function v3PriceEndpointFixture(): array
{
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'qa_endpoint_v3_pieces',
        'booking_value' => 'qa_endpoint_v3_pieces',
        'title' => 'اختبار قطع',
        'is_active' => true,
    ]);
    $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'QA workload quote preview']);
    $item = $catalog->items()->create([
        'item_key' => 'restaurant.furnace',
        'source_type' => 'property',
        'source_id' => $source->id,
        'label_ar' => 'أفران صناعية',
        'measurement_mode' => 'piece',
        'required' => true,
        'is_enabled' => true,
    ]);
    $item->tariffRules()->create([
        'rule_key' => 'furnace-rule',
        'cleaning_mode' => 'regular',
        'scope_key' => 'whole',
        'quantity_min' => '0',
        'quantity_max' => '40',
        'unit_price' => '50000.00',
        'unit_effort_minutes' => '250.00',
        'is_enabled' => true,
    ]);
    DB::table('cleaning_v3_catalog_versions')->where('id', $catalog->id)->update([
        'status' => 'published',
        'published_at' => now(),
        'published_digest' => str_repeat('a', 64),
    ]);

    return [$catalog->fresh(), [
        'catalogVersionKey' => $catalog->version_key,
        'catalogRevision' => str_repeat('a', 64),
        'lines' => [[
            'itemKey' => 'restaurant.furnace',
            'cleaningMode' => 'regular',
            'scopeKey' => 'whole',
            'quantity' => '3',
        ]],
    ]];
}

test('workload preview is authenticated and requires exact approved published catalog', function (): void {
    [$version, $payload] = v3PriceEndpointFixture();
    $url = '/api/v1/cleaning/v3/workload/preview';
    $this->postJson($url, $payload)->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->postJson($url, $payload)->assertStatus(503)
        ->assertJsonPath('code', 'V3_CATALOG_UNAVAILABLE');

    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('b', 64));
    $this->postJson($url, $payload)->assertStatus(503)
        ->assertJsonPath('code', 'V3_CATALOG_UNAVAILABLE');
});

test('workload preview returns derived money and real staffing needs but cannot book or charge', function (): void {
    [$version, $payload] = v3PriceEndpointFixture();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));

    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $result = $this->postJson('/api/v1/cleaning/v3/workload/preview', $payload)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.estimatedBaseAmountSyp', '150000.00')
        ->assertJsonPath('data.estimatedEffortMinutes', '750.00')
        ->assertJsonPath('data.capacity.estimatedWorkers', 3)
        ->assertJsonPath('data.financialState', 'preview_only')
        ->assertJsonPath('data.canQuote', false)
        ->assertJsonPath('data.canBook', false)
        ->assertJsonPath('data.canSettle', false)
        ->assertJsonPath('data.quoteId', null)
        ->assertJsonPath('data.quoteSignature', null);

    expect($result->json('data.capacity.workerLoads'))->toHaveCount(3)
        ->and($version->fresh()->status)->toBe('published');
});

test('price preview rejects client money, worker-count overrides and stale catalog revisions', function (): void {
    [$version, $payload] = v3PriceEndpointFixture();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));

    $this->postJson('/api/v1/cleaning/v3/workload/preview', [
        ...$payload, 'totalPrice' => '0.00',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_V3_PREVIEW_FIELDS');

    $this->postJson('/api/v1/cleaning/v3/workload/preview', [
        ...$payload, 'requiredWorkers' => 1,
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_V3_PREVIEW_FIELDS');

    $this->postJson('/api/v1/cleaning/v3/workload/preview', [
        ...$payload, 'catalogRevision' => str_repeat('b', 64),
    ])->assertStatus(409)->assertJsonPath('code', 'CATALOG_CHANGED');

    $this->postJson('/api/v1/cleaning/v3/workload/preview', [
        ...$payload, 'lines' => [[...$payload['lines'][0], 'unitPrice' => '1.00']],
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_V3_TARIFF_LINES');
});

test('public quote and V3 booking routes stay guarded even with an accepted preview', function (): void {
    [$version, $payload] = v3PriceEndpointFixture();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));

    $this->postJson('/api/v1/cleaning/v3/workload/preview', $payload)->assertOk();
    $this->postJson('/api/v1/cleaning/v3/quotes', [])->assertStatus(503)
        ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
    $this->postJson('/api/v1/cleaning/v3/bookings', [])->assertStatus(503)
        ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
});


test('form-based workload preview derives lines from required answers and the chosen cleaning mode', function (): void {
    [$version, $payload] = v3PriceEndpointFixture();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $url = '/api/v1/cleaning/v3/workload/preview';
    $form = [
        'catalogVersionKey' => $version->version_key,
        'catalogRevision' => str_repeat('a', 64),
        'answers' => ['restaurant.furnace' => 3],
        'cleaningMode' => 'regular',
    ];
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertOk()
        ->assertJsonPath('data.items.0.wholePreviewModes', ['regular'])
        ->assertDontSee('unit_price')->assertDontSee('250.00');
    $response = $this->postJson($url, $form)->assertOk()
        ->assertJsonPath('data.estimatedBaseAmountSyp', '150000.00')
        ->assertJsonPath('data.capacity.estimatedWorkers', 3)
        ->assertJsonPath('data.cleaningMode', 'regular')
        ->assertJsonPath('data.pricedItemKeys', ['restaurant.furnace'])
        ->assertJsonPath('data.quoteId', null);
    expect($response->json('data.formFingerprint'))->toMatch('/^[a-f0-9]{64}$/');
    $changed = $this->postJson($url, [...$form, 'answers' => ['restaurant.furnace' => '2']])
        ->assertOk()->assertJsonPath('data.capacity.estimatedWorkers', 2);
    expect($changed->json('data.formFingerprint'))->not->toBe($response->json('data.formFingerprint'));
    $this->postJson($url, [...$form, 'answers' => []])
        ->assertStatus(422)->assertJsonPath('code', 'REQUIRED_PUBLISHED_FIELD_MISSING');
    $this->postJson($url, [...$form, 'answers' => ['restaurant.furnace' => '2.5']])
        ->assertStatus(422)->assertJsonPath('code', 'NUMBER_OUT_OF_RANGE');
    $this->postJson($url, [...$form, 'cleaningMode' => 'deep'])
        ->assertStatus(422)->assertJsonPath('code', 'V3_FORM_PRICING_RULE_UNSUPPORTED');
    $this->postJson($url, [...$form, 'cleaningMode' => null])
        ->assertStatus(422)->assertJsonPath('code', 'V3_CLEANING_MODE_REQUIRED');
    $this->postJson($url, [...$form, 'lines' => $payload['lines']])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_V3_PREVIEW_FIELDS');
});


test('custom deep scope pricing uses published components once and rejects invalid mixtures', function (): void {
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'qa_v3_deep_scope', 'booking_value' => 'qa_v3_deep_scope',
        'title' => 'QA custom scope', 'is_active' => true,
    ]);
    $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'QA custom deep scopes']);
    $item = $catalog->items()->create([
        'item_key' => 'room', 'source_type' => 'property', 'source_id' => $source->id,
        'label_ar' => 'Room', 'measurement_mode' => 'count', 'required' => true,
        'is_enabled' => true, 'input_rules' => ['scopeLabels' => ['fixtures' => 'التجهيزات']],
    ]);
    foreach ([
        ['whole', '100000', '120'], ['floors', '30000', '30'],
        ['walls', '40000', '50'], ['fixtures', '5000', '10'],
    ] as [$scope, $price, $effort]) {
        $item->tariffRules()->create([
            'rule_key' => 'deep-'.$scope, 'cleaning_mode' => 'deep', 'scope_key' => $scope,
            'quantity_min' => '0', 'quantity_max' => '10', 'unit_price' => $price,
            'unit_effort_minutes' => $effort, 'is_enabled' => true,
        ]);
    }
    DB::table('cleaning_v3_catalog_versions')->where('id', $catalog->id)->update([
        'status' => 'published', 'published_at' => now(), 'published_digest' => str_repeat('a', 64),
    ]);
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertOk()
        ->assertJsonPath('data.items.0.deepScopeChoices.0', ['key' => 'fixtures', 'label' => 'التجهيزات'])
        ->assertJsonPath('data.items.0.deepScopeChoices.1.key', 'floors')
        ->assertJsonPath('data.items.0.deepScopeChoices.3.key', 'whole')
        ->assertDontSee('unit_price');
    $url = '/api/v1/cleaning/v3/workload/preview';
    $form = [
        'catalogVersionKey' => $catalog->version_key, 'catalogRevision' => str_repeat('a', 64),
        'cleaningMode' => 'deep', 'answers' => ['room' => 2],
    ];
    $whole = $this->postJson($url, [...$form, 'scopeSelections' => ['room' => ['whole']]])
        ->assertOk()->assertJsonPath('data.estimatedBaseAmountSyp', '200000.00')
        ->assertJsonPath('data.estimatedEffortMinutes', '240.00');
    $custom = $this->postJson($url, [...$form, 'scopeSelections' => ['room' => ['walls', 'floors']]])
        ->assertOk()->assertJsonPath('data.estimatedBaseAmountSyp', '140000.00')
        ->assertJsonPath('data.estimatedEffortMinutes', '160.00')
        ->assertJsonPath('data.pricedItemKeys', ['room'])
        ->assertJsonPath('data.scopeSelections.room', ['floors', 'walls'])
        ->assertJsonPath('data.canBook', false);
    expect($custom->json('data.formFingerprint'))->not->toBe($whole->json('data.formFingerprint'));
    $again = $this->postJson($url, [...$form, 'scopeSelections' => ['room' => ['floors', 'walls']]])->assertOk();
    expect($again->json('data.formFingerprint'))->toBe($custom->json('data.formFingerprint'));
    foreach ([[], ['whole', 'floors'], ['floors', 'floors'], ['unknown'], null] as $bad) {
        $this->postJson($url, [...$form, 'scopeSelections' => ['room' => $bad]])->assertStatus(422);
    }
    $this->postJson($url, [...$form, 'scopeSelections' => ['extra' => ['whole']]])->assertStatus(422);
    $this->postJson($url, [...$form, 'cleaningMode' => 'regular', 'scopeSelections' => ['room' => ['floors']]])->assertStatus(422);
});


test('scheduled form preview rejects under-staffed or short visits using real indivisible tasks', function (): void {
    [$version, $payload] = v3PriceEndpointFixture();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $version->version_key,
        'catalogRevision' => str_repeat('a', 64), 'cleaningMode' => 'regular',
        'answers' => ['restaurant.furnace' => 3],
        'appointments' => [[
            'appointmentKey' => 'first',
            'visits' => [[
                'occurrenceKey' => 'visit-first', 'scheduledDate' => now('Asia/Damascus')->addDay()->toDateString(),
                'scheduledTime' => '08:00', 'durationMinutes' => 480, 'requiredWorkers' => 3,
            ]],
        ]],
    ];
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $this->postJson($url, $form)->assertOk()
        ->assertJsonPath('data.capacityCheck.checked', true)
        ->assertJsonPath('data.capacityCheck.visits.0.minimumWorkers', 3)
        ->assertJsonPath('data.capacityCheck.requiresActualWorkerConflictCheck', true)
        ->assertJsonPath('data.canCreateBooking', false);
    $bad = $form;
    $bad['appointments'][0]['visits'][0]['requiredWorkers'] = 2;
    $this->postJson($url, $bad)->assertStatus(422)->assertJsonPath('code', 'CAPACITY_INFEASIBLE');
    $bad = $form;
    $bad['appointments'][0]['visits'][0]['durationMinutes'] = 240;
    $this->postJson($url, $bad)->assertStatus(422)->assertJsonPath('code', 'CAPACITY_INFEASIBLE');
    $split = $form;
    $split['appointments'][0]['visits'][] = [
        ...$form['appointments'][0]['visits'][0], 'occurrenceKey' => 'visit-second',
        'scheduledTime' => '16:00', 'durationMinutes' => 120,
    ];
    $this->postJson($url, $split)->assertStatus(422)->assertJsonPath('code', 'CAPACITY_INFEASIBLE');
    unset($form['cleaningMode']);
    $this->postJson($url, $form)->assertOk()->assertJsonPath('data.capacityCheck.checked', false);
});


test('one split service distributes all three indivisible units once across multiple days with conserved tariffs', function (): void {
    [$version, $payload] = v3PriceEndpointFixture();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $date = now('Asia/Damascus')->addDay()->toDateString();
    $later = now('Asia/Damascus')->addDays(2)->toDateString();
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $version->version_key,
        'catalogRevision' => str_repeat('a', 64), 'cleaningMode' => 'regular',
        'answers' => ['restaurant.furnace' => 3],
        'appointments' => [[
            'appointmentKey' => 'job',
            'visits' => [
                ['occurrenceKey' => 'first', 'scheduledDate' => $date, 'scheduledTime' => '08:00', 'durationMinutes' => 300, 'requiredWorkers' => 2],
                ['occurrenceKey' => 'second', 'scheduledDate' => $later, 'scheduledTime' => '08:00', 'durationMinutes' => 300, 'requiredWorkers' => 1],
            ],
        ]],
    ];
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $result = $this->postJson($url, $form)->assertOk()
        ->assertJsonPath('data.capacityCheck.totalEstimatedBaseAmountSyp', '150000.00')
        ->assertJsonPath('data.capacityCheck.visits.0.estimatedBaseAmountSyp', '100000.00')
        ->assertJsonPath('data.capacityCheck.visits.1.estimatedBaseAmountSyp', '50000.00')
        ->assertJsonPath('data.capacityCheck.visits.0.taskGroups.0.quantity', '2.00')
        ->assertJsonPath('data.capacityCheck.visits.1.taskGroups.0.quantity', '1.00')
        ->assertJsonPath('data.capacityCheck.visits.0.effortMinutes', 500)
        ->assertJsonPath('data.capacityCheck.visits.1.effortMinutes', 250)
        ->assertJsonPath('data.canCreateBooking', false);
    $this->postJson($url, $form)->assertOk()->assertJsonPath('data.draftFingerprint', $result->json('data.draftFingerprint'));
    $repeat = $form;
    $repeat['appointments'][] = ['appointmentKey' => 'repeat', 'visits' => [
        ['occurrenceKey' => 'third', 'scheduledDate' => $later, 'scheduledTime' => '14:00', 'durationMinutes' => 300, 'requiredWorkers' => 3],
    ]];
    $this->postJson($url, $repeat)->assertOk()->assertJsonPath('data.capacityCheck.totalEstimatedBaseAmountSyp', '300000.00');
    $bad = $form;
    $bad['appointments'][0]['visits'][0]['requiredWorkers'] = 1;
    $this->postJson($url, $bad)->assertStatus(422)->assertJsonPath('code', 'CAPACITY_INFEASIBLE');
    $bad = $form;
    unset($bad['cleaningMode']);
    $this->postJson($url, $bad)->assertStatus(422)->assertJsonPath('code', 'APPOINTMENT_MUST_USE_ONE_LOCAL_DATE');
});

test('approved continuous split conserves full-tier quantity and rounded money across visits', function (): void {
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'qa_split_surface', 'booking_value' => 'qa_split_surface',
        'title' => 'Test surface', 'is_active' => true,
    ]);
    $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'Split surface']);
    $item = $catalog->items()->create([
        'item_key' => 'surface', 'source_type' => 'property', 'source_id' => $source->id,
        'label_ar' => 'Surface', 'measurement_mode' => 'sqm',
        'required' => true, 'is_enabled' => true,
        'input_rules' => ['splitUnitQuantity' => '1.00'],
    ]);
    $item->tariffRules()->create([
        'rule_key' => 'surface-tier', 'cleaning_mode' => 'regular', 'scope_key' => 'whole',
        'quantity_min' => '2.00', 'quantity_max' => '10.00',
        'unit_price' => '10.01', 'unit_effort_minutes' => '300.00', 'is_enabled' => true,
    ]);
    DB::table('cleaning_v3_catalog_versions')->where('id', $catalog->id)->update([
        'status' => 'published', 'published_at' => now(), 'published_digest' => str_repeat('a', 64),
    ]);
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $form = [
        'contractVersion' => 3, 'catalogVersionKey' => $catalog->version_key,
        'catalogRevision' => str_repeat('a', 64), 'cleaningMode' => 'regular',
        'answers' => ['surface' => 2.35],
        'appointments' => [['appointmentKey' => 'job', 'visits' => [
            ['occurrenceKey' => 'first', 'scheduledDate' => now('Asia/Damascus')->addDay()->toDateString(), 'scheduledTime' => '08:00', 'durationMinutes' => 480, 'requiredWorkers' => 1],
            ['occurrenceKey' => 'second', 'scheduledDate' => now('Asia/Damascus')->addDays(2)->toDateString(), 'scheduledTime' => '08:00', 'durationMinutes' => 480, 'requiredWorkers' => 1],
        ]]],
    ];
    $response = $this->postJson('/api/v1/cleaning/v3/visit-plan/preview', $form)
        ->assertOk()->assertJsonPath('data.capacityCheck.totalEstimatedBaseAmountSyp', '23.52');
    $quantity = '0.00'; $amount = '0.00'; $effort = 0;
    foreach ($response->json('data.capacityCheck.visits') as $visit) {
        foreach ($visit['taskGroups'] as $group) {
            $quantity = bcadd($quantity, $group['quantity'], 2);
            $amount = bcadd($amount, $group['estimatedBaseAmountSyp'], 2);
            $effort += $group['effortMinutes'];
        }
    }
    expect($quantity)->toBe('2.35')->and($amount)->toBe('23.52')->and($effort)->toBe(705);
});
