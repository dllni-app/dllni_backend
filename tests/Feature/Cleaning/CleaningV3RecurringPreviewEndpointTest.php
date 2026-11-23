<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

uses(RefreshDatabase::class);

function v3RecurringEndpointInput(): array
{
    $start = now('Asia/Damascus')->addDays(4);

    return [
        'seriesKey' => 'weekly-qa-123',
        'catalogVersionKey' => 'placeholder',
        'catalogRevision' => str_repeat('a', 64),
        'startDate' => $start->format('Y-m-d'),
        'startTime' => '10:00',
        'weekdays' => [$start->isoWeekday()],
        'intervalWeeks' => 1,
        'occurrenceCount' => 2,
        'durationMinutes' => 120,
        'requiredWorkers' => 2,
    ];
}

function v3PublishRecurringCatalog(): CleaningV3CatalogVersion
{
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'QA recurring test version']);
    DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)->update([
        'status' => 'published',
        'published_at' => now(),
        'published_digest' => str_repeat('a', 64),
    ]);

    return $version->fresh();
}

test('V3 recurrence API requires authentication and explicit approved published catalog', function (): void {
    $version = v3PublishRecurringCatalog();
    $input = [
        ...v3RecurringEndpointInput(),
        'catalogVersionKey' => $version->version_key,
    ];
    $this->postJson('/api/v1/cleaning/v3/recurrence/preview', $input)->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $this->postJson('/api/v1/cleaning/v3/recurrence/preview', $input)->assertStatus(503)
        ->assertJsonPath('code', 'V3_CATALOG_UNAVAILABLE');

    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('b', 64));
    $this->postJson('/api/v1/cleaning/v3/recurrence/preview', $input)->assertStatus(503)
        ->assertJsonPath('code', 'V3_CATALOG_UNAVAILABLE');
});

test('V3 recurrence preview returns independent persistent identities but no booking or payment', function (): void {
    $version = v3PublishRecurringCatalog();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $input = [
        ...v3RecurringEndpointInput(),
        'catalogVersionKey' => $version->version_key,
    ];

    $before = CleaningBooking::query()->count();
    $result = $this->postJson('/api/v1/cleaning/v3/recurrence/preview', $input)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.contractVersion', 3)
        ->assertJsonPath('data.recurrence.frequency', 'weekly')
        ->assertJsonPath('data.summary.visitCount', 2)
        ->assertJsonPath('data.canCreateBooking', false)
        ->assertJsonPath('data.requiresFundingAdmission', true)
        ->assertJsonPath('data.quoteId', null)
        ->assertJsonPath('data.quoteSignature', null)
        ->assertDontSee('fundingSourceId');

    $first = $result->json('data.appointments.0.visits.0');
    $second = $result->json('data.appointments.1.visits.0');
    expect($first['occurrenceKey'])->toBe('weekly-qa-123-0001')
        ->and($second['occurrenceKey'])->toBe('weekly-qa-123-0002')
        ->and($first['visitId'])->toBeNull()
        ->and($second['visitId'])->toBeNull()
        ->and(CleaningBooking::query()->count())->toBe($before);
});

test('V3 recurrence preview refuses stale approved catalog versions and invalid repeated weekdays', function (): void {
    $version = v3PublishRecurringCatalog();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $input = [
        ...v3RecurringEndpointInput(),
        'catalogVersionKey' => $version->version_key,
    ];

    $this->postJson('/api/v1/cleaning/v3/recurrence/preview', [
        ...$input, 'catalogRevision' => str_repeat('b', 64),
    ])->assertStatus(409)->assertJsonPath('code', 'CATALOG_CHANGED');

    $this->postJson('/api/v1/cleaning/v3/recurrence/preview', [
        ...$input, 'weekdays' => [$input['weekdays'][0], $input['weekdays'][0]],
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_V3_RECURRENCE_WEEKDAY');

    $this->postJson('/api/v1/cleaning/v3/recurrence/preview', [
        ...$input, 'quotedPrice' => '1.00',
    ])->assertStatus(422)->assertJsonPath('code', 'INVALID_V3_RECURRENCE_RULE');
});
