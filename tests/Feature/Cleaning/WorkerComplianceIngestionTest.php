<?php

declare(strict_types=1);

use App\Models\Worker;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config()->set('cleaning.worker_compliance.enabled', true);
    config()->set('cleaning.worker_compliance.enabled_platforms', ['android', 'ios']);
    config()->set('cleaning.worker_compliance.notice_version', 'notice-v1');
    $this->worker = Worker::factory()->create();
    Sanctum::actingAs($this->worker->user);
});

function enrollWorkerForCompliance(Worker $worker): void
{
    DB::table('worker_compliance_states')->insert([
        'worker_id' => $worker->id,
        'is_enrolled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('keeps compliance collection disabled by default', function (): void {
    config()->set('cleaning.worker_compliance.enabled', false);
    enrollWorkerForCompliance($this->worker);

    $this->postJson('/api/v1/cleaning/worker/compliance/session', [
        'installationId' => Str::random(32),
        'platform' => 'android',
        'build' => '1.0.0',
        'noticeVersion' => 'notice-v1',
    ])->assertForbidden();

    expect(DB::table('worker_compliance_states')->where('worker_id', $this->worker->id)->value('epoch'))->toBeNull();
});

it('returns only feature and enrollment metadata from config', function (): void {
    enrollWorkerForCompliance($this->worker);

    $this->getJson('/api/v1/cleaning/worker/compliance/config?platform=android&build=1.0.0')
        ->assertOk()
        ->assertJsonPath('featureEnabled', true)
        ->assertJsonPath('enrolled', true)
        ->assertJsonMissingPath('latitude')
        ->assertJsonMissingPath('longitude');
});

it('creates an idempotent leased epoch for an enrolled worker', function (): void {
    enrollWorkerForCompliance($this->worker);
    $payload = [
        'installationId' => Str::random(32),
        'platform' => 'android',
        'build' => '1.0.0',
        'noticeVersion' => 'notice-v1',
    ];

    $first = $this->postJson('/api/v1/cleaning/worker/compliance/session', $payload)->assertOk();
    $second = $this->postJson('/api/v1/cleaning/worker/compliance/session', $payload)->assertOk();

    expect($first->json('epoch'))->toBe($second->json('epoch'));
    $this->assertDatabaseHas('worker_compliance_states', [
        'worker_id' => $this->worker->id,
        'epoch' => $first->json('epoch'),
        'notice_version' => 'notice-v1',
    ]);
});

it('rejects outdated notice acknowledgement and workers without enrollment', function (): void {
    enrollWorkerForCompliance($this->worker);
    $this->postJson('/api/v1/cleaning/worker/compliance/session', [
        'installationId' => Str::random(32),
        'platform' => 'ios',
        'build' => '1.0.0',
        'noticeVersion' => 'old-notice',
    ])->assertStatus(409);

    $otherWorker = Worker::factory()->create();
    Sanctum::actingAs($otherWorker->user);
    $this->postJson('/api/v1/cleaning/worker/compliance/session', [
        'installationId' => Str::random(32),
        'platform' => 'ios',
        'build' => '1.0.0',
        'noticeVersion' => 'notice-v1',
    ])->assertForbidden();
});

it('accepts and encrypts recent fixes, reports duplicates and rejects late fixes', function (): void {
    enrollWorkerForCompliance($this->worker);
    $session = $this->postJson('/api/v1/cleaning/worker/compliance/session', [
        'installationId' => Str::random(32),
        'platform' => 'android',
        'build' => '1.0.0',
        'noticeVersion' => 'notice-v1',
    ])->assertOk();
    $sampleId = (string) Str::uuid();
    $sample = [
        'id' => $sampleId,
        'capturedAt' => now()->subMinute()->toIso8601String(),
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'accuracyM' => 12.5,
    ];

    $response = $this->postJson('/api/v1/cleaning/worker/compliance/samples', [
        'epoch' => $session->json('epoch'),
        'permissionState' => 'always',
        'locationServicesEnabled' => true,
        'samples' => [$sample, [
            ...$sample,
            'capturedAt' => now()->subMinutes(20)->toIso8601String(),
        ]],
    ])->assertOk();

    $response->assertJsonPath('acceptedIds.0', $sampleId)
        ->assertJsonPath('duplicateIds', [])
        ->assertJsonPath('rejected.0.reason', 'late_sample');

    $storedSample = DB::table('worker_compliance_samples')->where('client_id', $sampleId)->first();
    expect(Crypt::decryptString($storedSample->latitude_encrypted))->toBe('33.5138')
        ->and(Crypt::decryptString($storedSample->longitude_encrypted))->toBe('36.2765')
        ->and($storedSample->quality)->toBe('usable');

    $replay = $this->postJson('/api/v1/cleaning/worker/compliance/samples', [
        'epoch' => $session->json('epoch'),
        'permissionState' => 'always',
        'locationServicesEnabled' => true,
        'samples' => [$sample],
    ])->assertOk();
    $replay->assertJsonPath('duplicateIds.0', $sampleId);
});

it('rejects samples from an obsolete or expired epoch', function (): void {
    enrollWorkerForCompliance($this->worker);

    $this->postJson('/api/v1/cleaning/worker/compliance/samples', [
        'epoch' => (string) Str::uuid(),
        'permissionState' => 'always',
        'locationServicesEnabled' => true,
        'samples' => [],
    ])->assertStatus(409);
});

it('accepts a health heartbeat without location samples', function (): void {
    enrollWorkerForCompliance($this->worker);
    $session = $this->postJson('/api/v1/cleaning/worker/compliance/session', [
        'installationId' => Str::random(32),
        'platform' => 'android',
        'build' => '1.0.0',
        'noticeVersion' => 'notice-v1',
    ])->assertOk();

    $this->postJson('/api/v1/cleaning/worker/compliance/samples', [
        'epoch' => $session->json('epoch'),
        'permissionState' => 'always',
        'locationServicesEnabled' => true,
        'samples' => [],
    ])->assertOk()->assertJsonPath('acceptedIds', []);

    expect(DB::table('worker_compliance_samples')->where('worker_id', $this->worker->id)->count())->toBe(0);
});

it('purges expired samples while retaining unexpired samples', function (): void {
    $baseSample = [
        'worker_id' => $this->worker->id,
        'epoch' => (string) Str::uuid(),
        'captured_at' => now(),
        'received_at' => now(),
        'latitude_encrypted' => Crypt::encryptString('33'),
        'longitude_encrypted' => Crypt::encryptString('36'),
        'accuracy_m' => 10,
        'quality' => 'usable',
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $expiredId = (string) Str::uuid();
    $retainedId = (string) Str::uuid();
    DB::table('worker_compliance_samples')->insert([
        [...$baseSample, 'client_id' => $expiredId, 'expires_at' => now()->subSecond()],
        [...$baseSample, 'client_id' => $retainedId, 'expires_at' => now()->addDay()],
    ]);

    $this->artisan('cleaning:purge-compliance-samples')->assertSuccessful();

    $this->assertDatabaseMissing('worker_compliance_samples', ['client_id' => $expiredId]);
    $this->assertDatabaseHas('worker_compliance_samples', ['client_id' => $retainedId]);
});

it('revokes the current session idempotently', function (): void {
    enrollWorkerForCompliance($this->worker);
    $session = $this->postJson('/api/v1/cleaning/worker/compliance/session', [
        'installationId' => Str::random(32),
        'platform' => 'android',
        'build' => '1.0.0',
        'noticeVersion' => 'notice-v1',
    ])->assertOk();

    $this->deleteJson('/api/v1/cleaning/worker/compliance/session')->assertNoContent();
    $this->deleteJson('/api/v1/cleaning/worker/compliance/session')->assertNoContent();
    $this->postJson('/api/v1/cleaning/worker/compliance/samples', [
        'epoch' => $session->json('epoch'),
        'permissionState' => 'always',
        'locationServicesEnabled' => true,
        'samples' => [],
    ])->assertStatus(409);
});
