<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogDraftValidator;

uses(RefreshDatabase::class);

test('platform equipment uses separate published price and shared buffered inventory across visits', function (): void {
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY, 'code' => 'qa_equipment_work',
        'booking_value' => 'qa_equipment_work', 'title' => 'QA work', 'is_active' => true,
    ]);
    $assets = collect(['QA-TOOL-A', 'QA-TOOL-B'])->map(fn ($code) => CleaningSpecialServiceEquipment::query()->create([
        'name' => 'QA hose', 'asset_code' => $code, 'status' => 'available',
        'buffer_before_minutes' => 15, 'buffer_after_minutes' => 15, 'is_active' => true,
    ]));
    $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'QA platform equipment']);
    $work = $catalog->items()->create([
        'item_key' => 'furnace', 'source_type' => 'property', 'source_id' => $source->id,
        'label_ar' => 'Furnace', 'measurement_mode' => 'piece', 'required' => true, 'is_enabled' => true,
    ]);
    $work->tariffRules()->create([
        'rule_key' => 'furnace-regular', 'cleaning_mode' => 'regular', 'scope_key' => 'whole',
        'quantity_min' => '1', 'quantity_max' => '10', 'unit_price' => '50000.00',
        'unit_effort_minutes' => '60.00', 'is_enabled' => true,
    ]);
    $equipment = $catalog->items()->create([
        'item_key' => 'hose', 'source_type' => 'equipment', 'source_id' => $assets[0]->id,
        'label_ar' => 'Hose', 'measurement_mode' => 'piece', 'required' => false, 'is_enabled' => true,
        'input_rules' => ['equipmentAssetIds' => [$assets[1]->id], 'equipmentUnitPriceSyp' => '7500.50'],
    ]);
    expect(app(CleaningV3CatalogDraftValidator::class)->review($catalog))->not->toContain('TARIFF_MISSING:hose', 'INVALID_EQUIPMENT_CONFIGURATION:hose');
    $catalog->items()->create([
        'item_key' => 'duplicate-hose', 'source_type' => 'equipment', 'source_id' => $assets[1]->id,
        'label_ar' => 'Duplicate hose', 'measurement_mode' => 'piece', 'is_enabled' => true, 'sort_order' => 100,
        'input_rules' => ['equipmentUnitPriceSyp' => '100.00'],
    ]);
    expect(app(CleaningV3CatalogDraftValidator::class)->review($catalog))->toContain('OVERLAPPING_EQUIPMENT_POOL:duplicate-hose');
    $catalog->items()->where('item_key', 'duplicate-hose')->firstOrFail()->delete();
    $digest = str_repeat('a', 64);
    DB::table('cleaning_v3_catalog_versions')->where('id', $catalog->id)->update([
        'status' => 'published', 'published_at' => now(), 'published_digest' => $digest,
    ]);
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', $digest);
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertOk()
        ->assertJsonPath('data.items.1.sourceType', 'equipment')
        ->assertJsonPath('data.items.1.constraints.max', 2)
        ->assertJsonPath('data.items.1.deepScopeChoices', [])
        ->assertDontSee('equipmentAssetIds')->assertDontSee('QA-TOOL-A')->assertDontSee('7500.50');
    $form = [
        'catalogVersionKey' => $catalog->version_key, 'catalogRevision' => $digest,
        'cleaningMode' => 'regular', 'answers' => ['furnace' => 2, 'hose' => 1],
    ];
    $preview = '/api/v1/cleaning/v3/workload/preview';
    $this->postJson($preview, $form)->assertOk()
        ->assertJsonPath('data.estimatedBaseAmountSyp', '100000.00')
        ->assertJsonPath('data.estimatedEffortMinutes', '120.00')
        ->assertJsonPath('data.equipmentPreview.estimatedEquipmentAmountPerVisitSyp', '7500.50')
        ->assertJsonPath('data.equipmentPreview.items.0.currentlyAvailableUnits', 2)
        ->assertJsonPath('data.equipmentPreview.canReserve', false);
    $this->postJson($preview, [...$form, 'answers' => ['furnace' => 2, 'hose' => 0]])->assertOk()
        ->assertJsonPath('data.equipmentPreview.items', []);
    $this->postJson($preview, [...$form, 'answers' => ['furnace' => 2, 'hose' => 3]])->assertStatus(422);
    $this->postJson($preview, [
        'catalogVersionKey' => $catalog->version_key, 'catalogRevision' => $digest,
        'lines' => [['itemKey' => 'hose', 'cleaningMode' => 'regular', 'scopeKey' => 'whole', 'quantity' => '1']],
    ])->assertStatus(422);
    $date = now('Asia/Damascus')->addDays(2)->toDateString();
    $plan = [...$form, 'contractVersion' => 3, 'appointments' => [[
        'appointmentKey' => 'job',
        'visits' => [
            ['occurrenceKey' => 'v1', 'scheduledDate' => $date, 'scheduledTime' => '08:00', 'durationMinutes' => 60, 'requiredWorkers' => 1],
            ['occurrenceKey' => 'v2', 'scheduledDate' => $date, 'scheduledTime' => '09:00', 'durationMinutes' => 60, 'requiredWorkers' => 1],
        ],
    ]]];
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $before = CleaningEquipmentReservation::query()->count();
    $this->postJson($url, $plan)->assertOk()
        ->assertJsonPath('data.capacityCheck.totalEstimatedBaseAmountSyp', '100000.00')
        ->assertJsonPath('data.capacityCheck.equipmentPreview.totalEstimatedEquipmentAmountSyp', '15001.00')
        ->assertJsonPath('data.capacityCheck.equipmentPreview.visits.0.items.0.quantity', 1)
        ->assertJsonPath('data.capacityCheck.equipmentPreview.requiresLockedRecheck', true)
        ->assertDontSee('assetId')->assertDontSee('QA-TOOL-A');
    expect(CleaningEquipmentReservation::query()->count())->toBe($before);
    $booking = CleaningBooking::factory()->create();
    $reserved = CleaningEquipmentReservation::query()->create([
        'cleaning_special_service_equipment_id' => $assets[0]->id,
        'cleaning_booking_id' => $booking->id,
        'reserved_from' => \Carbon\CarbonImmutable::parse($date.' 07:00', 'Asia/Damascus')->utc(),
        'reserved_until' => \Carbon\CarbonImmutable::parse($date.' 10:30', 'Asia/Damascus')->utc(),
        'status' => 'reserved',
    ]);
    $this->postJson($url, $plan)->assertStatus(422)->assertJsonPath('code', 'V3_EQUIPMENT_UNAVAILABLE');
    $plan['appointments'][0]['visits'][1]['scheduledTime'] = '09:30';
    $this->postJson($url, $plan)->assertOk();
    $reserved->update(['status' => 'return_pending_confirmation']);
    $assets[1]->update(['status' => 'in_use']);
    $this->postJson($url, $plan)->assertStatus(422)->assertJsonPath('code', 'V3_EQUIPMENT_UNAVAILABLE');
    expect(CleaningEquipmentReservation::query()->count())->toBe($before + 1);
    expect($assets[1]->fresh()->status)->toBe('in_use');
});
