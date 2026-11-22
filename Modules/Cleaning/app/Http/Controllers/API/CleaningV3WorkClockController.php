<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningV3WorkClockAccessPolicy;
use Modules\Cleaning\Services\CleaningV3WorkClockProjectionService;

/**
 * Read-only V3 clock for the booking customer, accepted visit workers and
 * currently assigned supervisors. V2 and other customers' visits stay hidden.
 */
final class CleaningV3WorkClockController
{
    public function __invoke(
        Request $request,
        int $booking,
        int $session,
        CleaningV3WorkClockProjectionService $clock,
        CleaningV3WorkClockAccessPolicy $access,
    ): JsonResponse {
        $parent = CleaningBooking::query()->findOrFail($booking);
        abort_unless((string) ($parent->contract_version ?? '') === 'v3', 404);

        $visit = $parent->sessions()->whereKey($session)->firstOrFail();
        abort_unless($access->canRead($request->user(), $parent, $visit), 404);

        return response()->json([
            'success' => true,
            'data' => $clock->project($visit),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
