<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningV3WorkClockProjectionService;

/**
 * Customer-owned V3 work clock read. V2 and other people's visits remain
 * invisible; worker/supervisor roles need their own explicit visit policy
 * before this read can be expanded to them.
 */
final class CleaningV3WorkClockController
{
    public function __invoke(
        Request $request,
        int $booking,
        int $session,
        CleaningV3WorkClockProjectionService $clock,
    ): JsonResponse {
        $parent = CleaningBooking::query()->findOrFail($booking);
        abort_unless(
            (string) ($parent->contract_version ?? '') === 'v3'
                && (int) $parent->customer_id === (int) $request->user()?->getAuthIdentifier(),
            404,
        );

        $visit = $parent->sessions()->whereKey($session)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $clock->project($visit),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
