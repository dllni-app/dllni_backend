<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Cleaning\Services\CleaningV3TreasuryAllocationVerifier;

uses(Tests\TestCase::class);

test('the treasury verifier requires an authentic unexpired and fully backed allocation', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    config()->set([
        'cleaning_v3_catalog.treasury_provider' => 'isolated-qa-custodian',
        'cleaning_v3_catalog.treasury_public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
    ]);
    $now = CarbonImmutable::parse('2026-11-26T01:00:00Z');
    $allocation = [
        'schemaVersion' => 1,
        'provider' => 'isolated-qa-custodian',
        'owner' => 'cleaning_platform',
        'currency' => 'SYP',
        'creditId' => 'qa-verifier-credit',
        'allocationId' => (string) Str::uuid(),
        'bookingId' => 10,
        'visitId' => 20,
        'quoteId' => (string) Str::uuid(),
        'pricingRevision' => 1,
        'pricingDigest' => hash('sha256', 'qa-verifier-price'),
        'grossCreditSyp' => '200000.00',
        'reservedSyp' => '100000.00',
        'issuedAt' => '2026-11-26T01:00:00Z',
        'expiresAt' => '2026-11-26T02:00:00Z',
        'state' => 'reserved',
        'supersedesAllocationId' => null,
    ];
    $sign = static function (array $payload) use ($keyPair): array {
        ksort($payload);
        $signature = sodium_crypto_sign_detached(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            sodium_crypto_sign_secretkey($keyPair),
        );
        return ['payload' => $payload, 'signature' => base64_encode($signature)];
    };
    $verifier = app(CleaningV3TreasuryAllocationVerifier::class);
    $signed = $sign($allocation);
    expect($verifier->verify($signed, $now))->toEqual($allocation);
    expect(fn () => $verifier->verify([...$signed, 'payload' => [...$allocation, 'visitId' => 21]], $now))
        ->toThrow(DomainException::class, 'V3_TREASURY_ALLOCATION_SIGNATURE_INVALID');
    expect(fn () => $verifier->verify($sign([...$allocation, 'reservedSyp' => '200001.00']), $now))
        ->toThrow(DomainException::class, 'V3_TREASURY_ALLOCATION_INVALID');
    expect(fn () => $verifier->verify($signed, $now->addHour()))
        ->toThrow(DomainException::class, 'V3_TREASURY_ALLOCATION_EXPIRED');

    $commercialKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    config()->set('cleaning_v3_catalog.treasury_public_key', base64_encode($commercialKey));
    expect(fn () => $verifier->verify($signed, $now))
        ->toThrow(DomainException::class, 'V3_TREASURY_ALLOCATION_SIGNATURE_INVALID');
});
