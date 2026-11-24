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
