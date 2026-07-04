<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;

final class WorkerHomepageEligibilityController
{
    public function __construct(
        private readonly WorkerHomepageController $homepageController,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $response = ($this->homepageController)($request);
        $payload = $response->getData(true);
        $eligibility = $payload['commissionCapacityEligibility'] ?? null;

        if (is_array($eligibility)) {
            $availableCount = (int) ($eligibility['availableNewOrdersCount'] ?? 0);
            $blockedCount = (int) ($eligibility['blockedNewOrdersCount'] ?? 0);
            $allVisibleCandidatesBlocked = $blockedCount > 0 && $availableCount === 0;

            if (! $allVisibleCandidatesBlocked) {
                $eligibility['canReceiveNewRequests'] = true;
                $eligibility['canAcceptNewBookings'] = true;
                $eligibility['reasonCode'] = WorkerOrderSolvencyService::REASON_ELIGIBLE;
                $eligibility['message'] = 'Your available commission capacity can receive new requests.';
                $payload['commissionCapacityEligibility'] = $eligibility;
            }
        }

        return response()->json($payload, $response->getStatusCode(), $response->headers->all());
    }
}
