<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogPublicSerializer;

uses(RefreshDatabase::class);

it('blocks the public V3 endpoint with the default hold even if a database record looks published', function (): void {
    config()->set('cleaning_v3_catalog.read_enabled', false);
    $response = $this->getJson('/api/v1/cleaning/catalog/v3')
        ->assertStatus(503)
        ->assertJsonPath('code', 'V3_CATALOG_UNAVAILABLE');
    expect((string) $response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

it('never leaks draft catalog data, even with customer catalog reads enabled', function (): void {
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('0', 64));
    CleaningV3CatalogVersion::query()->create(['title' => 'Confidential admin draft']);
    $this->getJson('/api/v1/cleaning/catalog/v3')
        ->assertStatus(503)
        ->assertDontSee('Confidential admin draft');
});

it('serves a verified snapshot with stable item keys, safe localized fields and conditional ETags', function (): void {
    config()->set('cleaning_v3_catalog.read_enabled', true);
    $home = CleaningHomeType::query()->create([
        'code' => 'v3_api_home',
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'booking_value' => 'house',
        'title' => 'منزل',
        'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'QA expected public version']);
    $item = $version->items()->create([
        'item_key' => 'property.room_area',
        'source_type' => 'property',
        'source_id' => $home->id,
        'label_ar' => 'المساحة',
        'label_en' => 'Area',
        'measurement_mode' => 'sqm',
        'required' => true,
        'input_rules' => [
            'componentId' => 'numeric',
            'min' => 8,
            'max' => 1000,
            'step' => 1,
            'descriptionAr' => 'أدخل المساحة بالمتر',
            'descriptionEn' => 'Enter square meters',
            'internalPrice' => 100000,
            'workerLatitude' => 36.1,
        ],
    ]);
    $item->presets()->create([
        'preset_key' => 'small',
        'label_ar' => 'صغير',
        'label_en' => 'Small',
        'minimum_value' => 8,
        'maximum_value' => 50,
    ]);
    // This raw SQL simulates an already approved immutable publication record
    // solely inside the isolated RefreshDatabase test transaction.
    DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)->update([
        'status' => 'published',
        'published_at' => now(),
        'published_digest' => str_repeat('a', 64),
    ]);
    // A read flag without an exact approved digest must still deny access.
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertStatus(503);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));

    $ar = $this->getJson('/api/v1/cleaning/catalog/v3', ['Accept-Language' => 'ar'])
        ->assertOk()
        ->assertJsonPath('data.schemaVersion', 3)
        ->assertJsonPath('data.capabilities.quoteEnabled', false)
        ->assertJsonPath('data.capabilities.createEnabled', false)
        ->assertJsonPath('data.items.0.key', 'property.room_area')
        ->assertJsonPath('data.items.0.label', 'المساحة')
        ->assertJsonPath('data.items.0.componentId', 'numeric')
        ->assertJsonPath('data.items.0.constraints.min', 8)
        ->assertJsonPath('data.items.0.choices.0.key', 'small')
        ->assertDontSee('internalPrice')
        ->assertDontSee('workerLatitude')
        ->assertDontSee('100000');

    $etag = $ar->headers->get('ETag');
    expect($etag)->toBeString()->not->toBeEmpty();
    $this->withHeaders(['If-None-Match' => $etag, 'Accept-Language' => 'ar'])
        ->get('/api/v1/cleaning/catalog/v3')->assertStatus(304);

    $en = $this->getJson('/api/v1/cleaning/catalog/v3', ['Accept-Language' => 'en-US'])
        ->assertOk()
        ->assertJsonPath('data.items.0.label', 'Area')
        ->assertJsonPath('data.items.0.description', 'Enter square meters')
        ->assertJsonPath('data.items.0.choices.0.label', 'Small');

    expect($en->headers->get('ETag'))->not->toBe($etag)
        ->and(CleaningV3CatalogPublicSerializer::SUPPORTED_COMPONENT_IDS)
        ->toContain('numeric', 'stepper', 'select', 'multiselect', 'room_builder', 'date_time');
});

it('advertises an explicit unsupported control rather than silently dropping the required field', function (): void {
    config()->set('cleaning_v3_catalog.read_enabled', true);
    $home = CleaningHomeType::query()->create([
        'code' => 'v3_unhandled_home',
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'title' => 'آخر',
        'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'QA unknown field']);
    $version->items()->create([
        'item_key' => 'property.custom',
        'source_type' => 'property',
        'source_id' => $home->id,
        'label_ar' => 'إلزامي مخصص',
        'measurement_mode' => 'fixed',
        'required' => true,
        'input_rules' => ['componentId' => 'unregistered_3d_field'],
    ]);
    DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)->update([
        'status' => 'published',
        'published_at' => now(),
        'published_digest' => str_repeat('b', 64),
    ]);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('b', 64));

    $this->getJson('/api/v1/cleaning/catalog/v3')
        ->assertOk()
        ->assertJsonPath('data.items.0.key', 'property.custom')
        ->assertJsonPath('data.items.0.required', true)
        ->assertJsonPath('data.items.0.supportedByServer', false)
        ->assertJsonPath('data.items.0.componentId', 'unregistered_3d_field');
});
