<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

uses(RefreshDatabase::class);

test('published property memberships isolate required fields in both workload and visit previews', function (): void {
    $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'QA property catalog']);
    foreach (['apartment', 'restaurant'] as $key) {
        $source = CleaningHomeType::query()->create([
            'section' => CleaningHomeType::SECTION_PROPERTY,
            'code' => 'qa_property_'.$key, 'booking_value' => 'qa_property_'.$key,
            'title' => $key, 'is_active' => true,
        ]);
        $catalog->items()->create([
            'item_key' => $key, 'source_type' => 'property', 'source_id' => $source->id,
            'label_ar' => $key, 'measurement_mode' => 'fixed', 'required' => false,
            'is_enabled' => true,
        ]);
        $room = \Modules\Cleaning\Models\CleaningRoomType::query()->create([
            'name' => 'QA '.$key, 'is_active' => true, 'code' => 'qa-'.$key,
        ]);
        $item = $catalog->items()->create([
            'item_key' => $key.'.rooms', 'source_type' => 'room', 'source_id' => $room->id,
            'label_ar' => $key.' rooms', 'measurement_mode' => 'count', 'required' => true,
            'is_enabled' => true, 'input_rules' => ['propertyKeys' => [$key]],
        ]);
        $item->tariffRules()->create([
            'rule_key' => $key.'-regular', 'cleaning_mode' => 'regular', 'scope_key' => 'whole',
            'quantity_min' => '0', 'quantity_max' => '10', 'unit_price' => $key === 'apartment' ? '10000' : '20000',
            'unit_effort_minutes' => '60', 'is_enabled' => true,
        ]);
    }
    $digest = str_repeat('d', 64);
    DB::table('cleaning_v3_catalog_versions')->where('id', $catalog->id)->update([
        'status' => 'published', 'published_at' => now(), 'published_digest' => $digest,
    ]);
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', $digest);
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->getJson('/api/v1/cleaning/catalog/v3')->assertOk()
        ->assertJsonFragment(['propertyKeys' => ['apartment']])
        ->assertJsonFragment(['propertyKeys' => ['restaurant']]);
    $form = [
        'catalogVersionKey' => $catalog->version_key, 'catalogRevision' => $digest,
        'propertyKey' => 'apartment', 'answers' => ['apartment.rooms' => 2],
    ];
    $price = '/api/v1/cleaning/v3/workload/preview';
    $visit = '/api/v1/cleaning/v3/visit-plan/preview';
    $schedule = ['contractVersion' => 3, 'appointments' => [[
        'appointmentKey' => 'a1', 'visits' => [[
            'occurrenceKey' => 'v1', 'scheduledDate' => now('Asia/Damascus')->addDay()->toDateString(),
            'scheduledTime' => '09:00', 'durationMinutes' => 120, 'requiredWorkers' => 1,
        ]],
    ]]];
    $this->postJson($price, [...$form, 'cleaningMode' => 'regular'])->assertOk()
        ->assertJsonPath('data.propertyKey', 'apartment')
        ->assertJsonPath('data.estimatedBaseAmountSyp', '20000.00');
    $this->postJson($price, [...$form, 'propertyKey' => 'restaurant', 'answers' => ['restaurant.rooms' => 2], 'cleaningMode' => 'regular'])
        ->assertOk()->assertJsonPath('data.estimatedBaseAmountSyp', '40000.00');
    $this->postJson($visit, [...$form, ...$schedule])->assertOk()->assertJsonPath('data.validatedAnswerCount', 1);
    foreach ([$price => ['cleaningMode' => 'regular'], $visit => $schedule] as $url => $extra) {
        $this->postJson($url, [...$form, ...$extra, 'propertyKey' => null])->assertStatus(422)->assertJsonPath('code', 'V3_PROPERTY_REQUIRED');
        $this->postJson($url, [...$form, ...$extra, 'answers' => []])->assertStatus(422)->assertJsonPath('code', 'REQUIRED_PUBLISHED_FIELD_MISSING');
        $this->postJson($url, [...$form, ...$extra, 'answers' => ['apartment.rooms' => 2, 'restaurant.rooms' => 2]])
            ->assertStatus(422)->assertJsonPath('code', 'UNKNOWN_CATALOG_FIELD');
    }
});
