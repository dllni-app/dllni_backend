<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3QuoteArchiveService;
use Modules\Cleaning\Services\CleaningV3SignedQuoteEnvelope;
use Modules\Cleaning\Services\CleaningV3VisitDiscountAllocator;

uses(RefreshDatabase::class);

/** @return array{0:User,1:CleaningV3CatalogVersion,2:array<string,mixed>,3:CarbonImmutable} */
function v3ArchiveFixture(): array
{
    $user = User::factory()->create(['is_active' => true]);
    $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'archive audit v3']);
    DB::table('cleaning_v3_catalog_versions')->where('id', $catalog->id)->update([
        'status' => CleaningV3CatalogVersion::PUBLISHED,
        'published_digest' => str_repeat('a', 64),
        'published_at' => '2026-11-24 12:00:00',
    ]);
    $catalog = $catalog->fresh();
    config()->set('cleaning_v3_catalog.read_enabled', true);
    config()->set('cleaning_v3_catalog.approved_publication_digest', str_repeat('a', 64));
    $priced = app(CleaningV3VisitDiscountAllocator::class)->allocate([
        ['visitKey' => 'visit-01', 'pricedWorkers' => 2, 'eligibleNetBeforeDiscount' => '650000.00'],
    ], ['type' => 'fixed', 'amount' => '50000.00']);

    $snapshot = [
        'contractVersion' => 3,
        'catalogVersionKey' => (string) $catalog->version_key,
        'catalogRevision' => str_repeat('a', 64),
        'draftFingerprint' => str_repeat('b', 64),
        'pricingRevision' => 1,
        'currency' => 'SYP',
        'visits' => [[
            'visitKey' => 'visit-01',
            'appointmentKey' => 'appointment-01',
            'occurrenceKey' => 'visit-01',
            'priceRevision' => 1,
            'pricedWorkers' => 2,
            'eligibleNetBeforeDiscount' => $priced['visits'][0]['eligibleNetBeforeDiscount'],
            'discountAllocated' => $priced['visits'][0]['discountAllocated'],
            'netBeforeAdditionalWorkerFee' => $priced['visits'][0]['netBeforeAdditionalWorkerFee'],
            'additionalWorkerFee' => $priced['visits'][0]['additionalWorkerFee'],
            'totalWithAdditionalWorkerFee' => $priced['visits'][0]['totalWithAdditionalWorkerFee'],
        ]],
        'eligibleBaseTotal' => $priced['eligibleBaseTotal'],
        'discountTotal' => $priced['discountTotal'],
        'netBeforeAdditionalWorkerFees' => $priced['netBeforeAdditionalWorkerFees'],
        'additionalWorkerFees' => $priced['additionalWorkerFees'],
        'totalWithAdditionalWorkerFees' => $priced['totalWithAdditionalWorkerFees'],
    ];

    $now = CarbonImmutable::parse('2026-11-24T12:05:00+00:00');
    $signed = app(CleaningV3SignedQuoteEnvelope::class)->seal(
        (int) $user->id, $snapshot, 15, $now,
    );

    return [$user, $catalog, $signed, $now];
}

test('archiving an already signed V3 quote persists exact identity and is idempotent', function (): void {
    [$customer, $catalog, $signed, $now] = v3ArchiveFixture();
    $key = (string) Str::uuid();
    $hash = str_repeat('f', 64);
    $archive = app(CleaningV3QuoteArchiveService::class);

    $first = $archive->archive($customer->id, $catalog->id, $key, $hash, $signed, $now);
    $retry = $archive->archive($customer->id, $catalog->id, $key, $hash, $signed, $now->addMinute());

    expect($first['replayed'])->toBeFalse()
        ->and($first['quoteId'])->toBe($signed['quoteId'])
        ->and($retry['replayed'])->toBeTrue()
        ->and($retry['quoteId'])->toBe($signed['quoteId'])
        ->and(DB::table('cleaning_booking_quotes')->count())->toBe(1);

    $row = DB::table('cleaning_booking_quotes')->where('id', $signed['quoteId'])->first();
    expect($row->contract_version)->toBe('v3')
        ->and((int) $row->catalog_version_id)->toBe($catalog->id)
        ->and((int) $row->customer_id)->toBe($customer->id)
        ->and((string) $row->signature)->toBe($signed['signature'])
        ->and(json_decode($row->snapshot, true)['snapshot']['additionalWorkerFees'])->toBe('150000.00')
        ->and($row->cleaning_booking_id)->toBeNull();

    Sanctum::actingAs($customer);
    $this->postJson('/api/v1/cleaning/v3/bookings', [])->assertStatus(503)
        ->assertJsonPath('code', 'V3_FINANCIAL_APPROVAL_REQUIRED');
});

test('conflicting retry inputs cannot replace an existing immutable V3 quote', function (): void {
    [$customer, $catalog, $signed, $now] = v3ArchiveFixture();
    $key = (string) Str::uuid();
    $archive = app(CleaningV3QuoteArchiveService::class);
    $archive->archive($customer->id, $catalog->id, $key, str_repeat('f', 64), $signed, $now);

    expect(fn () => $archive->archive(
        $customer->id, $catalog->id, $key, str_repeat('e', 64), $signed, $now,
    ))->toThrow(DomainException::class, 'V3_QUOTE_IDEMPOTENCY_CONFLICT');

    $newEnvelope = app(CleaningV3SignedQuoteEnvelope::class)
        ->seal($customer->id, $signed['snapshot'], 15, $now);
    expect(fn () => $archive->archive(
        $customer->id, $catalog->id, $key, str_repeat('f', 64), $newEnvelope, $now,
    ))->toThrow(DomainException::class, 'V3_QUOTE_IDEMPOTENCY_CONFLICT');

    expect(DB::table('cleaning_booking_quotes')->count())->toBe(1);
});

test('quote archival rejects expired signatures, foreign users and unavailable approved catalog', function (): void {
    [$customer, $catalog, $signed, $now] = v3ArchiveFixture();
    $archive = app(CleaningV3QuoteArchiveService::class);

    expect(fn () => $archive->archive(
        $customer->id, $catalog->id, (string) Str::uuid(), str_repeat('a', 64),
        $signed, $now->addMinutes(16),
    ))->toThrow(DomainException::class, 'V3_QUOTE_EXPIRED');

    expect(fn () => $archive->archive(
        User::factory()->create()->id, $catalog->id, (string) Str::uuid(),
        str_repeat('a', 64), $signed, $now,
    ))->toThrow(DomainException::class, 'INVALID_V3_QUOTE_ENVELOPE');

    config()->set('cleaning_v3_catalog.read_enabled', false);
    expect(fn () => $archive->archive(
        $customer->id, $catalog->id, (string) Str::uuid(), str_repeat('a', 64),
        $signed, $now,
    ))->toThrow(DomainException::class, 'V3_QUOTE_CATALOG_NOT_APPROVED');

    expect(DB::table('cleaning_booking_quotes')->count())->toBe(0);
});

test('archived quote identity must not be reused with another retry key', function (): void {
    [$customer, $catalog, $signed, $now] = v3ArchiveFixture();
    $archive = app(CleaningV3QuoteArchiveService::class);
    $archive->archive($customer->id, $catalog->id, (string) Str::uuid(),
        str_repeat('d', 64), $signed, $now);

    expect(fn () => $archive->archive(
        $customer->id, $catalog->id, (string) Str::uuid(), str_repeat('d', 64),
        $signed, $now,
    ))->toThrow(DomainException::class, 'V3_QUOTE_ID_ALREADY_USED');
    expect(DB::table('cleaning_booking_quotes')->count())->toBe(1);
});

test('quote replay cannot substitute a modified payload with the original signature', function (): void {
    [$customer, $catalog, $signed, $now] = v3ArchiveFixture();
    $key = (string) Str::uuid();
    $archive = app(CleaningV3QuoteArchiveService::class);
    $archive->archive($customer->id, $catalog->id, $key, str_repeat('f', 64), $signed, $now);

    $tampered = $signed;
    $tampered['snapshot']['visits'][0]['pricedWorkers'] = 3;

    expect(fn () => $archive->archive(
        $customer->id, $catalog->id, $key, str_repeat('f', 64), $tampered, $now,
    ))->toThrow(DomainException::class, 'V3_QUOTE_IDEMPOTENCY_CONFLICT');

    expect(DB::table('cleaning_booking_quotes')->count())->toBe(1);
});

test('a quote replay after expiry refuses the old record and never refreshes it', function (): void {
    [$customer, $catalog, $signed, $now] = v3ArchiveFixture();
    $key = (string) Str::uuid();
    $archive = app(CleaningV3QuoteArchiveService::class);
    $archive->archive($customer->id, $catalog->id, $key, str_repeat('f', 64), $signed, $now);
    $expiresBefore = DB::table('cleaning_booking_quotes')
        ->where('id', $signed['quoteId'])->value('expires_at');

    expect(fn () => $archive->archive(
        $customer->id, $catalog->id, $key, str_repeat('f', 64), $signed,
        $now->addMinutes(16),
    ))->toThrow(DomainException::class, 'V3_QUOTE_EXPIRED');

    expect(DB::table('cleaning_booking_quotes')->where('id', $signed['quoteId'])
        ->value('expires_at'))->toBe($expiresBefore)
        ->and(DB::table('cleaning_booking_quotes')->count())->toBe(1);
});

test('booking admission verifies the exact stored signed quote inside a locked transaction', function (): void {
    [$customer, $catalog, $signed, $now] = v3ArchiveFixture();
    $key = (string) Str::uuid();
    $hash = str_repeat('a', 64);
    app(CleaningV3QuoteArchiveService::class)->archive($customer->id, $catalog->id, $key, $hash, $signed, $now);
    $admission = app(Modules\Cleaning\Services\CleaningV3QuoteBookingAdmissionService::class);

    // RefreshDatabase wraps tests in a transaction; a call outside an
    // explicit nested transaction would still be inside that test transaction.
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    $snapshot = DB::transaction(fn (): array => $admission->assertStoredQuote(
        $customer->id, $signed['quoteId'], $key, $hash, $now,
    ));
    expect($snapshot)->toEqual($signed['snapshot'])
        ->and(DB::table('cleaning_booking_quotes')->where('id', $signed['quoteId'])
            ->value('cleaning_booking_id'))->toBeNull();

    foreach ([
        [$customer->id + 1, $key, $hash],
        [$customer->id, (string) Str::uuid(), $hash],
        [$customer->id, $key, str_repeat('e', 64)],
    ] as [$id, $retryKey, $retryHash]) {
        expect(fn () => DB::transaction(fn () => $admission->assertStoredQuote(
            $id, $signed['quoteId'], $retryKey, $retryHash, $now,
        )))->toThrow(DomainException::class, 'V3_BOOKING_QUOTE_NOT_FOUND');
    }

    $booking = Modules\Cleaning\Models\CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    DB::table('cleaning_booking_quotes')->where('id', $signed['quoteId'])
        ->update(['cleaning_booking_id' => $booking->id]);

    expect(fn () => DB::transaction(fn () => $admission->assertStoredQuote(
        $customer->id, $signed['quoteId'], $key, $hash, $now,
    )))->toThrow(DomainException::class, 'V3_BOOKING_QUOTE_ALREADY_CONSUMED');
});

test('booking admission rejects expiry, rolled back catalog and edited persisted signature', function (): void {
    [$customer, $catalog, $signed, $now] = v3ArchiveFixture();
    $key = (string) Str::uuid();
    $hash = str_repeat('f', 64);
    app(CleaningV3QuoteArchiveService::class)->archive($customer->id, $catalog->id, $key, $hash, $signed, $now);
    $admission = app(Modules\Cleaning\Services\CleaningV3QuoteBookingAdmissionService::class);

    expect(fn () => DB::transaction(fn () => $admission->assertStoredQuote(
        $customer->id, $signed['quoteId'], $key, $hash, $now->addMinutes(16),
    )))->toThrow(DomainException::class, 'V3_QUOTE_EXPIRED_OR_MISMATCHED');

    config()->set('cleaning_v3_catalog.read_enabled', false);
    expect(fn () => DB::transaction(fn () => $admission->assertStoredQuote(
        $customer->id, $signed['quoteId'], $key, $hash, $now,
    )))->toThrow(DomainException::class, 'V3_BOOKING_CATALOG_NOT_APPROVED');

    config()->set('cleaning_v3_catalog.read_enabled', true);
    DB::table('cleaning_booking_quotes')->where('id', $signed['quoteId'])
        ->update(['signature' => str_repeat('0', 64)]);
    expect(fn () => DB::transaction(fn () => $admission->assertStoredQuote(
        $customer->id, $signed['quoteId'], $key, $hash, $now,
    )))->toThrow(DomainException::class, 'V3_BOOKING_QUOTE_STORAGE_MISMATCH');
});
