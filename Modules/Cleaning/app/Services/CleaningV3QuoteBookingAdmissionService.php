<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Read-only pre-booking admission for a persisted signed V3 quote.
 *
 * MUST be called inside the SAME outer transaction that later locks funding,
 * persists a V3 booking, and links cleaning_booking_quotes.cleaning_booking_id.
 * This verifies quote identity only; no funding, tariff, inventory, refund or
 * settlement authority is ever inferred from a valid HMAC.
 */
final class CleaningV3QuoteBookingAdmissionService
{
    public function __construct(
        private readonly CleaningV3SignedQuoteEnvelope $signer,
    ) {}

    /**
     * @return array<string,mixed> Verified immutable quote snapshot.
     */
    /**
     * Explicit customer confirmation of a previously issued commercial quote.
     * Caller must recompute the current price/draft fingerprint under the same
     * booking transaction; this method never reserves funds or creates orders.
     */
    public function assertCustomerConfirmation(int $customerId, array $confirmation, string $currentPricingFingerprint,
        string $currentDraftFingerprint, ?CarbonImmutable $now = null): array
    {
        $fields = ['quoteId', 'idempotencyKey', 'requestHash', 'quoteSignature', 'acceptedTotalSyp', 'pricingRevision', 'pricingFingerprint', 'draftFingerprint'];
        if (array_diff(array_keys($confirmation), $fields) !== [] || count($confirmation) !== count($fields)
            || ! is_int($confirmation['pricingRevision'] ?? null)) {
            throw new DomainException('INVALID_V3_CUSTOMER_QUOTE_CONFIRMATION');
        }
        foreach (array_diff($fields, ['pricingRevision']) as $field) {
            if (! is_string($confirmation[$field] ?? null)) {
                throw new DomainException('INVALID_V3_CUSTOMER_QUOTE_CONFIRMATION');
            }
        }
        $snapshot = $this->assertStoredQuote($customerId, $confirmation['quoteId'], $confirmation['idempotencyKey'], $confirmation['requestHash'], $now);
        $signature = DB::table('cleaning_booking_quotes')->where('id', $confirmation['quoteId'])->value('signature');
        if (! is_string($signature) || ! hash_equals($signature, $confirmation['quoteSignature'])) {
            throw new DomainException('V3_QUOTE_SIGNATURE_MISMATCH');
        }
        $review = $snapshot['commercialReview'] ?? null;
        if (! is_array($review) || ! hash_equals($review['pricingFingerprint'], $confirmation['pricingFingerprint'])
            || ! hash_equals($review['draftFingerprint'], $confirmation['draftFingerprint'])
            || ! hash_equals($review['pricingFingerprint'], $currentPricingFingerprint)
            || ! hash_equals($review['draftFingerprint'], $currentDraftFingerprint)
            || $snapshot['pricingRevision'] !== $confirmation['pricingRevision']
            || ! hash_equals($snapshot['totalWithAdditionalWorkerFees'], $confirmation['acceptedTotalSyp'])) {
            throw new DomainException('REQUOTE_REQUIRED');
        }
        return $snapshot;
    }

    public function assertStoredQuote(
        int $customerId,
        string $quoteId,
        string $idempotencyKey,
        string $requestHash,
        ?CarbonImmutable $now = null,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('V3_BOOKING_TRANSACTION_REQUIRED');
        }
        if (! config('cleaning_v3_catalog.new_booking_admission_enabled', false)) {
            throw new DomainException('V3_NEW_BOOKINGS_PAUSED');
        }
        if ($customerId < 1 || ! Str::isUuid($quoteId) || ! Str::isUuid($idempotencyKey)
            || preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
            throw new DomainException('INVALID_V3_BOOKING_QUOTE_REFERENCE');
        }

        $row = DB::table('cleaning_booking_quotes')
            ->where('id', $quoteId)
            ->where('customer_id', $customerId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($row === null || $row->contract_version !== 'v3'
            || ! hash_equals((string) $row->request_hash, $requestHash)) {
            throw new DomainException('V3_BOOKING_QUOTE_NOT_FOUND');
        }

        if ($row->cleaning_booking_id !== null) {
            throw new DomainException('V3_BOOKING_QUOTE_ALREADY_CONSUMED');
        }

        $catalog = CleaningV3CatalogVersion::query()
            ->whereKey($row->catalog_version_id)
            ->where('status', CleaningV3CatalogVersion::PUBLISHED)
            ->whereNotNull('published_at')
            ->first();
        $approvedDigest = \Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::digest();

        if (! config('cleaning_v3_catalog.read_enabled', false)
            || ! is_string($approvedDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $approvedDigest) !== 1
            || $catalog === null
            || ! is_string($catalog->published_digest)
            || ! hash_equals($approvedDigest, $catalog->published_digest)) {
            throw new DomainException('V3_BOOKING_CATALOG_NOT_APPROVED');
        }

        $envelope = json_decode((string) $row->snapshot, true);
        if (! is_array($envelope)
            || (string) ($envelope['quoteId'] ?? '') !== (string) $row->id
            || ! is_string($envelope['signature'] ?? null)
            || ! hash_equals((string) $row->signature, $envelope['signature'])) {
            throw new DomainException('V3_BOOKING_QUOTE_STORAGE_MISMATCH');
        }

        $at = ($now ?? CarbonImmutable::now('UTC'))->utc();
        if (CarbonImmutable::parse((string) $row->expires_at, 'UTC') <= $at
            || ! hash_equals(
                CarbonImmutable::parse((string) $row->expires_at, 'UTC')->toIso8601String(),
                CarbonImmutable::parse((string) ($envelope['expiresAt'] ?? ''), 'UTC')->toIso8601String(),
            )) {
            throw new DomainException('V3_QUOTE_EXPIRED_OR_MISMATCHED');
        }

        return $this->signer->verify(
            $envelope,
            $customerId,
            (string) $catalog->version_key,
            $approvedDigest,
            $at,
        );
    }
}
