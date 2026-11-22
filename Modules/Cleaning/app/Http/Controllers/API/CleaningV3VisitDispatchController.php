<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningV3VisitDispatchService;

final class CleaningV3VisitDispatchController
{
    public function __invoke(
        Request $request,
        int $booking,
        int $session,
        CleaningV3VisitDispatchService $dispatch,
    ): JsonResponse {
        $parent = CleaningBooking::query()->findOrFail($booking);
        abort_unless((string) ($parent->contract_version ?? '') === 'v3'
            && $request->user()?->is_active
            && (int) $request->user()->id === (int) $parent->customer_id, 404);

        $visit = $parent->sessions()->whereKey($session)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $dispatch->project($visit),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
