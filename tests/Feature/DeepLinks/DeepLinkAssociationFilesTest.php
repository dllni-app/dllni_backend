<?php

declare(strict_types=1);

use function Pest\Laravel\getJson;

it('serves android asset links on both well-known and root endpoints', function (): void {
    config()->set('deep_links.android_app_package_name', 'com.alnadha.user');
    config()->set('deep_links.android_sha256_cert_fingerprints', [
        '06:1E:43:B2:49:72:88:13:AB:D4:8F:1F:DC:BF:35:90:A4:80:76:E5:7B:C9:5A:3E:8D:8C:E0:89:AD:AF:05:EE',
    ]);

    $wellKnown = getJson('/.well-known/assetlinks.json')->assertOk()->json();
    $root = getJson('/assetlinks.json')->assertOk()->json();

    expect($root)->toBe($wellKnown)
        ->and($root[0]['target']['package_name'])->toBe('com.alnadha.user')
        ->and($root[0]['target']['sha256_cert_fingerprints'])->toHaveCount(1);
});

it('serves apple app site association on both well-known and root endpoints', function (): void {
    config()->set('deep_links.ios_app_ids', ['C4S72M3DX2.com.alnadha.user']);
    config()->set('deep_links.ios_paths', [
        '/api/v1/user/*',
        '/product/*',
        '/restaurant/*',
        '/store/*',
        '/vote/*',
        '/group-order/*',
        '/s/*',
    ]);

    $wellKnown = getJson('/.well-known/apple-app-site-association')->assertOk()->json();
    $root = getJson('/apple-app-site-association')->assertOk()->json();

    expect($root)->toBe($wellKnown)
        ->and($root['applinks']['details'][0]['appID'])->toBe('C4S72M3DX2.com.alnadha.user')
        ->and($root['applinks']['details'][0]['paths'])->toContain('/store/*')
        ->and($root['applinks']['details'][0]['paths'])->toContain('/s/*');
});
