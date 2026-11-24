<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

uses(RefreshDatabase::class);

function v3VisitPreviewFixture(): array
{
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'qa_v3_visit_area',
        'booking_value' => 'qa_v3_visit_area',
        'title' => 'QA area',
        'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'QA visit preview']);
    $version->items()->create([
        'item_key' => 'area',
        'source_type' => 'property',
        'source_id' => $source->id,
        'label_ar' => 'Area',
        'measurement_mode' => 'sqm',
        'required' => true,
        'is_enabled' => true,
        'input_rules' => ['min' => 1, 'max' => 100, 'step' => 1],
    ]);
    $digest = str_repeat('a', 64);
    DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)->update([
        'status' => 'published',
        'published_at' => now(),
        'published_digest' => $digest,
    ]);
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', $digest);

    return [
        'contractVersion' => 3,
        'catalogVersionKey' => $version->version_key,
        'catalogRevision' => $digest,
        'answers' => ['area' => '25'],
        'appointments' => [[
            'appointmentKey' => 'appointment-1',
            'visits' => [[
                'occurrenceKey' => 'visit-2',
                'scheduledDate' => now('Asia/Damascus')->addDays(2)->format('Y-m-d'),
                'scheduledTime' => '10:00',
                'durationMinutes' => 120,
                'requiredWorkers' => 2,
            ]],
        ]],
    ];
}

test('V3 visit preview authenticates and binds valid answers without booking or funding writes', function (): void {
    $payload = v3VisitPreviewFixture();
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $this->postJson($url, $payload)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $tables = ['cleaning_bookings', 'cleaning_booking_quotes', 'cleaning_visit_funding_reservations'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->count();
    }
    $first = $this->postJson($url, $payload)->assertOk()
        ->assertJsonPath('data.summary.totalActiveMinutes', 120)
        ->assertJsonPath('data.summary.peakRequestedWorkers', 2)
        ->assertJsonPath('data.previewOnly', true)
        ->assertJsonPath('data.canQuote', false)
        ->assertJsonPath('data.canCreateBooking', false)
        ->assertJsonPath('data.quoteId', null);
    $second = $this->postJson($url, [...$payload, 'answers' => ['area' => '26']])->assertOk();
    expect($first->json('data.draftFingerprint'))->not->toBe($second->json('data.draftFingerprint'));
    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe($before[$table]);
    }
});

test('V3 visit preview rejects stale catalog, missing answers and unknown nested fields', function (): void {
    $payload = v3VisitPreviewFixture();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $this->postJson($url, [...$payload, 'catalogRevision' => str_repeat('b', 64)])
        ->assertStatus(409);
    $this->postJson($url, [...$payload, 'answers' => []])->assertStatus(422)
        ->assertJsonPath('code', 'REQUIRED_PUBLISHED_FIELD_MISSING');
    $this->postJson($url, [...$payload, 'unexpected' => true])->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_V3_PLAN_FIELDS');
    $group = $payload;
    $group['appointments'][0]['price'] = '1.00';
    $this->postJson($url, $group)->assertStatus(422)
        ->assertJsonPath('code', 'CLIENT_FINANCIAL_INPUT_FORBIDDEN');
    $visit = $payload;
    $visit['appointments'][0]['visits'][0]['visitId'] = 1;
    $this->postJson($url, $visit)->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_V3_PLAN_FIELDS');
});
