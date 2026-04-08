<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UserCleaningOrderQuoteService
{
    private const CACHE_KEY_PREFIX = 'user_cleaning_quote:';

    private const QUOTE_TTL_MINUTES = 15;

    private const QUOTE_REQUIRED_FROM = '2026-04-23 00:00:00';

    public function __construct(private UserCleaningOrderEstimationService $estimationService) {}

    /**
     * @param  array<string, mixed>  $normalizedInput
     * @param  array<string, mixed>  $estimation
     * @param  array<string, mixed>  $pricing
     * @return array{quoteId: string, expiresAt: string, algorithmVersion: string}
     */
    public function issueQuote(User $user, array $normalizedInput, array $estimation, array $pricing): array
    {
        $quoteId = 'clnq_'.Str::lower((string) Str::ulid());
        $expiresAt = CarbonImmutable::now()->addMinutes(self::QUOTE_TTL_MINUTES);

        $signature = $this->signature($normalizedInput, $estimation, $pricing);

        Cache::put($this->cacheKey($quoteId), [
            'quoteId' => $quoteId,
            'userId' => $user->id,
            'algorithmVersion' => $this->estimationService->algorithmVersion(),
            'signature' => $signature,
            'expiresAt' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'quoteId' => $quoteId,
            'expiresAt' => $expiresAt->toIso8601String(),
            'algorithmVersion' => $this->estimationService->algorithmVersion(),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalizedInput
     * @param  array<string, mixed>  $estimation
     * @param  array<string, mixed>  $pricing
     */
    public function validateQuote(
        mixed $quoteId,
        int $userId,
        array $normalizedInput,
        array $estimation,
        array $pricing,
        bool $required,
    ): void {
        $normalizedQuoteId = $this->normalizeQuoteId($quoteId);

        if ($normalizedQuoteId === null) {
            if ($required) {
                throw ValidationException::withMessages([
                    'quoteId' => ['A valid quoteId is required for this request.'],
                ]);
            }

            return;
        }

        $cached = Cache::get($this->cacheKey($normalizedQuoteId));

        if (! is_array($cached)) {
            throw ValidationException::withMessages([
                'quoteId' => ['The provided quoteId is invalid or expired.'],
            ]);
        }

        if ((int) ($cached['userId'] ?? 0) !== $userId) {
            throw ValidationException::withMessages([
                'quoteId' => ['The provided quoteId is invalid for this user.'],
            ]);
        }

        if (($cached['algorithmVersion'] ?? null) !== $this->estimationService->algorithmVersion()) {
            throw ValidationException::withMessages([
                'quoteId' => ['The provided quoteId is outdated. Please request a new price estimate.'],
            ]);
        }

        $signature = (string) ($cached['signature'] ?? '');
        $expectedSignature = $this->signature($normalizedInput, $estimation, $pricing);

        if ($signature === '' || ! hash_equals($signature, $expectedSignature)) {
            throw ValidationException::withMessages([
                'quoteId' => ['The provided quoteId does not match the current order details.'],
            ]);
        }
    }

    public function isQuoteRequiredNow(): bool
    {
        $requiredFrom = CarbonImmutable::parse(self::QUOTE_REQUIRED_FROM, (string) config('app.timezone'));

        return now()->greaterThanOrEqualTo($requiredFrom);
    }

    private function normalizeQuoteId(mixed $quoteId): ?string
    {
        if (! is_string($quoteId)) {
            return null;
        }

        $normalized = mb_trim($quoteId);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $normalizedInput
     * @param  array<string, mixed>  $estimation
     * @param  array<string, mixed>  $pricing
     */
    private function signature(array $normalizedInput, array $estimation, array $pricing): string
    {
        $payload = [
            'algorithmVersion' => $this->estimationService->algorithmVersion(),
            'normalizedInput' => $normalizedInput,
            'estimation' => $estimation,
            'pricing' => $pricing,
        ];

        $normalized = $this->normalizeForSignature($payload);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function cacheKey(string $quoteId): string
    {
        return self::CACHE_KEY_PREFIX.$quoteId;
    }

    private function normalizeForSignature(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForSignature($item);
            }

            if (! array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        if (is_float($value)) {
            return round($value, 6);
        }

        return $value;
    }
}
