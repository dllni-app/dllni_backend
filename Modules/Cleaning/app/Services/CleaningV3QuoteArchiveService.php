<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Persist an ALREADY approved, signed V3 quote in the pre-existing quote
 * registry. This is an audit/idempotency primitive, NOT a public quote
 * issuer, charge, funding reservation or booking writer.
 *
 * The upstream admission step must recompute the schedule and tariff against
 * the approved catalog and prove funding policy before calling this method.
 */
final class CleaningV3QuoteArchiveService
{
    public function __construct(
        private readonly CleaningV3SignedQuoteEnvelope $signer,
    ) {}

    /**
     * @param  array<string,mixed>  $signedEnvelope
     * @return array{quoteId:string,replayed:bool,expiresAt:string}
     */
    /**
     * Recover the original signed envelope before generating any replacement
     * quote after a lost response. Lock the same customer row as archive().
     * The request hash must be server-derived, not arbitrary client data.
     */
    public function findReplay(int $customerId, int $catalogVersionId, string $idempotencyKey,
        string $requestHash, ?CarbonImmutable $now = null): ?array
    {
        if ($customerId < 1 || $catalogVersionId < 1 || ! Str::isUuid($idempotencyKey)
            || preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
            throw new DomainException('INVALID_V3_QUOTE_ARCHIVE_INPUT');
        }
        $at = ($now ?? CarbonImmutable::now('UTC'))->utc();
        return DB::transaction(function () use ($customerId, $catalogVersionId, $idempotencyKey, $requestHash, $at): ?array {
            $customer = DB::table('users')->where('id', $customerId)->lockForUpdate()->first();
            if ($customer === null) {
                throw new DomainException('V3_QUOTE_CUSTOMER_NOT_FOUND');
            }
            $row = DB::table('cleaning_booking_quotes')->where('customer_id', $customerId)
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            if ($row->contract_version !== 'v3' || (int) $row->catalog_version_id !== $catalogVersionId
                || ! hash_equals((string) $row->request_hash, $requestHash)) {
                throw new DomainException('V3_QUOTE_IDEMPOTENCY_CONFLICT');
            }
            if (CarbonImmutable::parse((string) $row->expires_at, 'UTC') <= $at) {
                throw new DomainException('V3_QUOTE_EXPIRED');
            }
            $digest = config('cleaning_v3_catalog.approved_publication_digest');
            $catalog = CleaningV3CatalogVersion::query()->whereKey($catalogVersionId)
                ->where('status', CleaningV3CatalogVersion::PUBLISHED)->whereNotNull('published_at')->first();
            if (! config('cleaning_v3_catalog.read_enabled', false) || ! is_string($digest)
                || $catalog === null || ! is_string($catalog->published_digest)
                || ! hash_equals($digest, $catalog->published_digest)) {
                throw new DomainException('V3_QUOTE_CATALOG_NOT_APPROVED');
            }
            $envelope = json_decode((string) $row->snapshot, true);
            if (! is_array($envelope) || ($envelope['quoteId'] ?? null) !== (string) $row->id
                || ! is_string($envelope['signature'] ?? null) || ! hash_equals((string) $row->signature, $envelope['signature'])) {
                throw new DomainException('V3_BOOKING_QUOTE_STORAGE_MISMATCH');
            }
            $this->signer->verify($envelope, $customerId, (string) $catalog->version_key, $digest, $at);
            if (! hash_equals(CarbonImmutable::parse((string) $row->expires_at, 'UTC')->toIso8601String(),
                CarbonImmutable::parse($envelope['expiresAt'], 'UTC')->toIso8601String())) {
                throw new DomainException('V3_QUOTE_EXPIRED_OR_MISMATCHED');
            }
            return $envelope;
        }, 3);
    }


    public function archive(
        int $customerId,
        int $catalogVersionId,
        string $idempotencyKey,
        string $requestHash,
        array $signedEnvelope,
        ?CarbonImmutable $now = null,
    ): array {
        if ($customerId < 1 || $catalogVersionId < 1
            || ! Str::isUuid($idempotencyKey)
            || preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
            throw new DomainException('INVALID_V3_QUOTE_ARCHIVE_INPUT');
        }

        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();

        return DB::transaction(function () use (
            $customerId, $catalogVersionId, $idempotencyKey, $requestHash, $signedEnvelope, $now,
        ): array {
            // Lock the owning customer row to serialize requests with the same
            // customer/idempotency pair on MySQL and SQLite alike. The legacy
            // unique(customer_id,idempotency_key) remains the DB backstop.
            $customer = DB::table('users')->where('id', $customerId)->lockForUpdate()->first();
            if ($customer === null) {
                throw new DomainException('V3_QUOTE_CUSTOMER_NOT_FOUND');
            }

            $existing = DB::table('cleaning_booking_quotes')
                ->where('customer_id', $customerId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ((string) $existing->contract_version !== 'v3'
                    || (int) $existing->catalog_version_id !== $catalogVersionId
                    || ! hash_equals((string) $existing->request_hash, $requestHash)
                    || ! hash_equals((string) $existing->id, (string) ($signedEnvelope['quoteId'] ?? ''))
                    || ! hash_equals((string) $existing->signature, (string) ($signedEnvelope['signature'] ?? ''))
                    || json_decode((string) $existing->snapshot, true) !== $signedEnvelope) {
                    throw new DomainException('V3_QUOTE_IDEMPOTENCY_CONFLICT');
                }

                // A replay never extends the expiry or rewrites a snapshot.
                // An expired quote cannot be made usable by re-sending its key.
                if (CarbonImmutable::parse((string) $existing->expires_at, 'UTC') <= $now) {
                    throw new DomainException('V3_QUOTE_EXPIRED');
                }

                return [
                    'quoteId' => (string) $existing->id,
                    'replayed' => true,
                    'expiresAt' => CarbonImmutable::parse((string) $existing->expires_at, 'UTC')
                        ->toIso8601String(),
                ];
            }

            if (! config('cleaning_v3_catalog.new_quote_archive_enabled', false)) {
                throw new DomainException('V3_NEW_QUOTES_PAUSED');
            }

            $approvedDigest = config('cleaning_v3_catalog.approved_publication_digest');
            $catalog = CleaningV3CatalogVersion::query()->whereKey($catalogVersionId)
                ->where('status', CleaningV3CatalogVersion::PUBLISHED)
                ->whereNotNull('published_at')
                ->first();

            if (! config('cleaning_v3_catalog.read_enabled', false)
                || ! is_string($approvedDigest)
                || preg_match('/^[a-f0-9]{64}$/D', $approvedDigest) !== 1
                || $catalog === null
                || ! is_string($catalog->published_digest)
                || ! hash_equals($approvedDigest, $catalog->published_digest)) {
                throw new DomainException('V3_QUOTE_CATALOG_NOT_APPROVED');
            }

            $snapshot = $this->signer->verify(
                $signedEnvelope,
                $customerId,
                (string) $catalog->version_key,
                $approvedDigest,
                $now,
            );

            if (DB::table('cleaning_booking_quotes')
                ->where('id', $signedEnvelope['quoteId'])->exists()) {
                throw new DomainException('V3_QUOTE_ID_ALREADY_USED');
            }

            // Store the full immutable signed payload in the existing JSON
            // snapshot column; expose no public route while financial approval
            // and actual funding contracts are pending.
            DB::table('cleaning_booking_quotes')->insert([
                'id' => $signedEnvelope['quoteId'],
                'customer_id' => $customerId,
                'request_hash' => $requestHash,
                'signature' => $signedEnvelope['signature'],
                'snapshot' => json_encode($signedEnvelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'expires_at' => CarbonImmutable::parse($signedEnvelope['expiresAt'], 'UTC')
                    ->toDateTimeString(),
                'idempotency_key' => $idempotencyKey,
                'contract_version' => 'v3',
                'catalog_version_id' => $catalogVersionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'quoteId' => $signedEnvelope['quoteId'],
                'replayed' => false,
                'expiresAt' => $signedEnvelope['expiresAt'],
            ];
        }, 3);
    }
}
