<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV2VisitReplacementRequestService;

final class VisitReplacementApprovalController
{
    public function __construct(private readonly CleaningV2VisitReplacementRequestService $replacements) {}

    public function approve(Request $request, int $booking, int $visit, int $replacement): JsonResponse
    {
        $order = CleaningBooking::query()->findOrFail($booking);
        $session = CleaningBookingSession::query()->where('cleaning_booking_id', $order->id)
            ->where('sequence', $visit)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->replacements->approve(
            $request->user(), $order, $session, $replacement,
        )])->header('Cache-Control', 'private, no-store');
    }
}
