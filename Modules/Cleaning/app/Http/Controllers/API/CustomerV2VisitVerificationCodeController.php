<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningV2VisitVerificationService;

final class CustomerV2VisitVerificationCodeController
{
    public function __construct(private readonly CleaningV2VisitVerificationService $verification) {}

    public function __invoke(Request $request, int $booking, int $visit): JsonResponse
    {
        $order = CleaningBooking::query()->whereKey($booking)
            ->where('customer_id', $request->user()->id)->firstOrFail();
        $session = $order->sessions()->where('sequence', $visit)->firstOrFail();

        return response()->json(['success' => true, 'data' =>
            $this->verification->customerCode($request->user(), $order, $session),
        ])->header('Cache-Control', 'private, no-store');
    }
}
