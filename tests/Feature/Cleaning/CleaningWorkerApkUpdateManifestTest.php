<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function workerUpdateTestConfig(): array
{
    return [
        'enabled' => true,
        'package_id' => 'com.dllni.clOwner',
        'min_supported_version_code' => 4,
        'latest_version_code' => 7,
        'apk_sha256' => str_repeat('a', 64),
        'signing_certificate_sha256' => str_repeat('b', 64),
        'download_url' => 'https://updates.example.com/private/worker-v7.apk?ticket=test',
        'allowed_download_host' => 'updates.example.com',
    ];
}

function workerUpdateTestSignIn(): User
{
    $user = User::factory()->create(['is_active' => true]);
    Worker::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    return $user;
}

test('worker update manifest remains off without explicit trusted release metadata', function (): void {
    workerUpdateTestSignIn();
    Config::set('cleaning_worker_update', workerUpdateTestConfig());
    Config::set('cleaning_worker_update.enabled', false);

    $this->getJson('/api/v1/cleaning/worker/account/apk-update/manifest')
        ->assertStatus(503)
        ->assertJsonPath('code', 'WORKER_APK_UPDATE_NOT_AVAILABLE')
        ->assertDontSee('downloadUrl')
        ->assertDontSee('updates.example.com');
});

test('authenticated worker sees only the exact trusted package and signed release details', function (): void {
    workerUpdateTestSignIn();
    Config::set('cleaning_worker_update', workerUpdateTestConfig());

    $this->getJson('/api/v1/cleaning/worker/account/apk-update/manifest')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.schemaVersion', 1)
        ->assertJsonPath('data.packageId', 'com.dllni.clOwner')
        ->assertJsonPath('data.minSupportedVersionCode', 4)
        ->assertJsonPath('data.latestVersionCode', 7)
        ->assertJsonPath('data.apkSha256', str_repeat('a', 64))
        ->assertJsonPath('data.signingCertificateSha256', str_repeat('b', 64))
        ->assertJsonPath('data.downloadUrl', 'https://updates.example.com/private/worker-v7.apk?ticket=test');
});

test('worker updater refuses wrong domains, insecure schemes, fake certs and version inconsistencies', function (): void {
    workerUpdateTestSignIn();

    foreach ([
        ['package_id' => 'com.fake.worker'],
        ['min_supported_version_code' => 0],
        ['latest_version_code' => 3],
        ['apk_sha256' => str_repeat('z', 64)],
        ['signing_certificate_sha256' => str_repeat('0', 63)],
        ['download_url' => 'http://updates.example.com/private/worker.apk'],
        ['download_url' => 'https://evil.example.com/private/worker.apk'],
        ['download_url' => 'https://user:password@updates.example.com/private/worker.apk'],
        ['download_url' => 'https://updates.example.com/private/worker.apk#fragment'],
        ['download_url' => 'https://updates.example.com:444/private/worker.apk'],
        ['allowed_download_host' => '127.0.0.1'],
        ['allowed_download_host' => 'UPDATES.EXAMPLE.COM'],
    ] as $override) {
        Config::set('cleaning_worker_update', array_merge(workerUpdateTestConfig(), $override));

        $this->getJson('/api/v1/cleaning/worker/account/apk-update/manifest')
            ->assertStatus(503)
            ->assertJsonPath('code', 'WORKER_APK_UPDATE_NOT_AVAILABLE')
            ->assertDontSee('worker-v7.apk');
    }
});

test('private APK manifest cannot be accessed by unauthenticated, inactive or non-worker users', function (): void {
    Config::set('cleaning_worker_update', workerUpdateTestConfig());
    $url = '/api/v1/cleaning/worker/account/apk-update/manifest';

    $this->getJson($url)->assertUnauthorized();

    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $this->getJson($url)->assertNotFound();

    $worker = workerUpdateTestSignIn();
    $worker->forceFill(['is_active' => false])->save();
    $this->getJson($url)->assertNotFound();
});
