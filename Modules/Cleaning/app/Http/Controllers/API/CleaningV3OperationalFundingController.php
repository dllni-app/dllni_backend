<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV3OperationalFundingService;

final class CleaningV3OperationalFundingController
{
    public function show(int $booking, int $session, CleaningV3OperationalFundingService $service): JsonResponse
    {
        return $this->response(fn () => $service->review($booking, $session));
    }

    public function store(Request $request, int $booking, int $session, CleaningV3OperationalFundingService $service): JsonResponse
    {
        $data = $request->validate(['proof' => ['required', 'array:payload,signature']]);
        return $this->response(fn () => $service->import($booking, $session, $data['proof']));
    }

    private function response(callable $callback): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $callback()], 200, ['Cache-Control' => 'private, no-store']);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage(),
                'message' => 'تعذر اعتماد التمويل. تحقق من إثبات الخزينة المستقل والمبلغ وإصدار السعر.'], 409, ['Cache-Control' => 'private, no-store']);
        }
    }
}
