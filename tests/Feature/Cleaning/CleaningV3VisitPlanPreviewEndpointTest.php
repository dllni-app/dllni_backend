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
        ->assertJsonPath('data.additionalWorkerFeePreview.totalEstimatedFeeSyp', '150000.00')
        ->assertJsonPath('data.additionalWorkerFeePreview.visits.0.occurrenceKey', 'visit-2')
        ->assertJsonPath('data.additionalWorkerFeePreview.canCharge', false)
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

test('V3 customer preferences verify address revision, gender and own completed workers', function (): void {
    $payload = v3VisitPreviewFixture();
    $owner = User::factory()->create(['is_active' => true]);
    $outsider = User::factory()->create(['is_active' => true]);
    $address = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $owner->id, 'latitude' => '36.20000000', 'longitude' => '37.15000000',
        'street' => 'QA street', 'is_default' => true,
    ]);
    $otherAddress = \Modules\User\Models\UserAddress::factory()->create([
        'user_id' => $outsider->id, 'latitude' => '36.20000000', 'longitude' => '37.15000000',
        'street' => 'Private outsider street',
    ]);
    $worker = \App\Models\Worker::factory()->create();
    $otherWorker = \App\Models\Worker::factory()->create();
    $booking = \Modules\Cleaning\Models\CleaningBooking::factory()->create([
        'customer_id' => $owner->id, 'worker_id' => $worker->id, 'status' => 'completed',
    ]);
    \Modules\Cleaning\Models\CleaningBooking::factory()->create([
        'customer_id' => $outsider->id, 'worker_id' => $otherWorker->id, 'status' => 'completed',
    ]);
    $optionsUrl = '/api/v1/cleaning/v3/customer-preferences';
    $this->getJson($optionsUrl)->assertUnauthorized();
    Sanctum::actingAs($owner);
    $options = $this->getJson($optionsUrl)->assertOk()->assertJsonCount(1, 'data.addresses')
        ->assertJsonPath('data.addresses.0.addressId', $address->id)
        ->assertJsonPath('data.previousWorkers.0.workerId', $worker->id)
        ->assertJsonCount(1, 'data.previousWorkers')
        ->assertDontSee('Private outsider street');
    $selection = ['addressId' => $address->id, 'addressRevision' => $options->json('data.addresses.0.revision'),
        'genderPreference' => 'any', 'excludedWorkerIds' => [$worker->id]];
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $first = $this->postJson($url, [...$payload, 'preferences' => $selection])->assertOk()
        ->assertJsonPath('data.preferencesCheck.checked', true)
        ->assertJsonPath('data.preferencesCheck.genderPreference', 'any')
        ->assertJsonPath('data.preferencesCheck.excludedWorkerIds', [$worker->id])
        ->assertJsonPath('data.canCreateBooking', false);
    $female = $this->postJson($url, [...$payload, 'preferences' => [...$selection, 'genderPreference' => 'female']])->assertOk();
    expect($female->json('data.draftFingerprint'))->not->toBe($first->json('data.draftFingerprint'));
    foreach ([
        ['addressId' => $otherAddress->id],
        ['genderPreference' => null],
        ['excludedWorkerIds' => [$otherWorker->id]],
        ['excludedWorkerIds' => [$worker->id, $worker->id]],
        ['preferredWorkerId' => $worker->id],
    ] as $invalid) {
        $this->postJson($url, [...$payload, 'preferences' => [...$selection, ...$invalid]])->assertStatus(422);
    }
    $address->update(['street' => 'Changed QA street']);
    $this->postJson($url, [...$payload, 'preferences' => $selection])->assertStatus(422)
        ->assertJsonPath('code', 'V3_ADDRESS_CHANGED');

    $booking->forceFill(['contract_version' => 'v3', 'property_details' => ['v3_excluded_worker_ids' => [$worker->id]]])->save();
    expect($booking->excludesWorker($worker->id))->toBeTrue()
        ->and($booking->excludesWorker($otherWorker->id))->toBeFalse()
        ->and(\Modules\Cleaning\Models\CleaningBooking::query()->whereKey($booking->id)->withoutExcludedWorker($worker->id)->exists())->toBeFalse()
        ->and(\Modules\Cleaning\Models\CleaningBooking::query()->whereKey($booking->id)->withoutExcludedWorker($otherWorker->id)->exists())->toBeFalse();
    // An unadmitted V3 order is discoverable to neither worker. Once the
    // durable admission exists, the customer's own exclusion still applies.
    if (! $booking->sessions()->exists()) {
        $booking->sessions()->create([
            'sequence' => 1, 'session_type' => 'recurring_cleaning', 'calculation_mode' => 'task',
            'scheduled_date' => now()->addDay()->toDateString(), 'scheduled_time' => '09:00',
            'duration_hours' => 2, 'required_workers' => 1, 'status' => 'scheduled',
        ]);
    }
    \Tests\Support\CleaningV3AdmittedStateFixture::apply($booking);
    expect(\Modules\Cleaning\Models\CleaningBooking::query()->whereKey($booking->id)->withoutExcludedWorker($worker->id)->exists())->toBeFalse()
        ->and(\Modules\Cleaning\Models\CleaningBooking::query()->whereKey($booking->id)->withoutExcludedWorker($otherWorker->id)->exists())->toBeTrue();
    $session = new \Modules\Cleaning\Models\CleaningBookingSession();
    expect(app(\Modules\Cleaning\Services\CleaningBookingSessionWorkerEligibilityService::class)->check($booking, $session, $worker)['reasonCode'])->toBe('worker_excluded_by_customer');
});

test('V3 recurring final draft must match server generated stable edited schedule', function (): void {
    $payload = v3VisitPreviewFixture();
    Sanctum::actingAs(User::factory()->create(['is_active' => true]));
    $start = now('Asia/Damascus')->addDays(3);
    $rule = [
        'seriesKey' => 'series-qa',
        'catalogVersionKey' => $payload['catalogVersionKey'],
        'catalogRevision' => $payload['catalogRevision'],
        'startDate' => $start->toDateString(), 'startTime' => '08:00',
        'weekdays' => [$start->isoWeekday()], 'intervalWeeks' => 1,
        'occurrenceCount' => 3, 'durationMinutes' => 120, 'requiredWorkers' => 1,
        'exceptions' => [
            ['occurrenceKey' => 'series-qa-0001', 'action' => 'skip'],
            ['occurrenceKey' => 'series-qa-0002', 'action' => 'reschedule', 'scheduledDate' => $start->copy()->addDays(8)->toDateString(), 'scheduledTime' => '14:30'],
        ],
    ];
    $preview = $this->postJson('/api/v1/cleaning/v3/recurrence/preview', $rule)->assertOk()
        ->assertJsonPath('data.summary.visitCount', 2)
        ->assertJsonPath('data.skippedOccurrences.0.occurrenceKey', 'series-qa-0001')
        ->assertJsonPath('data.appointments.0.visits.0.occurrenceKey', 'series-qa-0002')
        ->assertJsonPath('data.appointments.0.visits.0.scheduledTime', '14:30');
    $payload['recurrenceRule'] = $preview->json('data.recurrenceRule');
    $payload['appointments'] = array_map(fn ($group) => [
        'appointmentKey' => $group['appointmentKey'],
        'visits' => [array_intersect_key($group['visits'][0], array_flip(['occurrenceKey', 'scheduledDate', 'scheduledTime', 'durationMinutes', 'requiredWorkers']))],
    ], $preview->json('data.appointments'));
    $url = '/api/v1/cleaning/v3/visit-plan/preview';
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.skippedOccurrences.0.occurrenceKey', 'series-qa-0001')
        ->assertJsonPath('data.canCreateBooking', false);
    $payload['appointments'][0]['visits'][0]['scheduledTime'] = '15:00';
    $this->postJson($url, $payload)->assertStatus(422)->assertJsonPath('code', 'V3_RECURRENCE_PLAN_MISMATCH');
    $rule['exceptions'][1]['scheduledDate'] = $start->copy()->addDays(14)->toDateString();
    $rule['exceptions'][1]['scheduledTime'] = '08:30';
    $this->postJson('/api/v1/cleaning/v3/recurrence/preview', $rule)->assertStatus(422)->assertJsonPath('code', 'V3_RECURRENCE_OVERLAP');
    $rule['exceptions'][1]['occurrenceKey'] = 'unknown';
    $this->postJson('/api/v1/cleaning/v3/recurrence/preview', $rule)->assertStatus(422)->assertJsonPath('code', 'INVALID_V3_RECURRENCE_EXCEPTION');
});
