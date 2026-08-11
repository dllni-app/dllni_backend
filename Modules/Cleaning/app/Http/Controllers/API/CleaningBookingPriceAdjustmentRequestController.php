<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Http\Requests\CleaningBookingPriceAdjustmentRequest as PriceAdjustmentFormRequest;
use Modules\Cleaning\Http\Resources\CleaningBookingPriceAdjustmentRequestResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingPriceAdjustmentService;

final class CleaningBookingPriceAdjustmentRequestController
{
    public function __construct(
        private readonly CleaningBookingPriceAdjustmentService $priceAdjustmentService,
    ) {}

    public function store(PriceAdjustmentFormRequest $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $worker = $request->user()?->worker;

        if (! $worker) {
            abort(403, 'User must have an associated worker.');
        }

        try {
            $adjustment = $this->priceAdjustmentService->requestFromWorker(
                booking: $cleaning_booking,
                worker: $worker,
                proposedTotalPrice: $request->validated('proposed_total_price'),
                reason: $request->validated('reason'),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'price_adjustment' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب التعديل للإدارة، يرجى الانتظار لحين التواصل معك ومع العميل لتأكيد السعر.',
            'data' => CleaningBookingPriceAdjustmentRequestResource::make($adjustment)->resolve($request),
        ], 201);
    }
}
