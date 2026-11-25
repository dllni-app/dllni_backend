<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV3SupervisorCashAccountingService;

final class SupervisorCashAccountingController
{
    public function index(Request $request, CleaningV3SupervisorCashAccountingService $service): JsonResponse
    {
        return $this->respond(fn () => $service->statistics($request->user()));
    }

    public function show(Request $request, int $booking, int $visit, CleaningV3SupervisorCashAccountingService $service): JsonResponse
    {
        return $this->respond(fn () => $service->review($booking, $visit, $request->user()));
    }

    public function report(Request $request, int $booking, int $visit, CleaningV3SupervisorCashAccountingService $service): JsonResponse
    {
        $data = $request->validate([
            'collectedAmountSyp' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/D'],
            'reference' => ['required', 'string', 'max:128'],
            'evidence' => ['required', 'string', 'min:10', 'max:500'],
            'cashReceivedAndPayablesPaid' => ['required', 'accepted'],
        ]);
        return $this->respond(fn () => $service->report($booking, $visit, $request->user(),
            $data['collectedAmountSyp'], $data['reference'], $data['evidence']));
    }

    private function respond(callable $fn): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()], 200, ['Cache-Control' => 'private, no-store']);
        } catch (DomainException $e) {
            return response()->json(['code' => $e->getMessage(),
                'message' => 'المحاسبة النقدية بحاجة إلى مراجعة. تحقق من اكتمال الزيارة وإثبات الحضور والمبالغ مع الإدارة.'], 409);
        }
    }
}
