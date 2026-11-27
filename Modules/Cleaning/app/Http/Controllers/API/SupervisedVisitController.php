<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV3SupervisedVisitService;

final class SupervisedVisitController
{
    public function __construct(private readonly CleaningV3SupervisedVisitService $visits) {}

    public function requestVerification(Request $request, int $booking, int $visit): JsonResponse
    {
        return $this->response($this->visits->requestVerification($request->user(), $booking, $visit));
    }

    public function customerCode(Request $request, int $booking, int $visit): JsonResponse
    {
        return $this->response($this->visits->customerCode($request->user(), $booking, $visit));
    }

    public function confirmVerification(Request $request, int $booking, int $visit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        return $this->response($this->visits->confirmVerification($request->user(), $booking, $visit, (string) $data['code']));
    }

    public function start(Request $request, int $booking, int $visit): JsonResponse
    {
        return $this->response($this->visits->start($request->user(), $booking, $visit));
    }

    public function requestHandover(Request $request, int $booking, int $visit): JsonResponse
    {
        return $this->response($this->visits->requestHandover($request->user(), $booking, $visit, \Modules\Cleaning\Services\CleaningCustomerIncidentService::validate($request)));
    }

    private function response(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
