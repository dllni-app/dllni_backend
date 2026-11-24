<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningEventType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

uses(RefreshDatabase::class);

function v3EventScheduleFixture(): array
{
    $event = CleaningEventType::query()->create([
        'name' => 'QA event', 'slug' => 'qa-v3-long-event', 'is_active' => true,
    ]);
    $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'QA event catalog']);
    $catalog->items()->create([
        'item_key' => 'event.qa', 'source_type' => 'event_type', 'source_id' => $event->id,
        'label_ar' => 'QA event', 'measurement_mode' => 'hour', 'is_enabled' => true,
        'required' => false,
    ]);
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
