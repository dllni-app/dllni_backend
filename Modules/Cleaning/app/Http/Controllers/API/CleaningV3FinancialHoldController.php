<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;

/**
 * Intentionally fail-closed while catalog publishing, tariffs, refund rules,
 * real clearing/funding and transactional V3 idempotency remain unapproved.
 * Never fall through to V2 booking creation or accept client-side prices.
 */
final class CleaningV3FinancialHoldController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'V3_FINANCIAL_APPROVAL_REQUIRED',
            'message' => 'V3 quotes and booking creation are not available until financial approval and verification are complete.',
            'retryable' => false,
        ], 503, ['Cache-Control' => 'private, no-store']);
    }
}
