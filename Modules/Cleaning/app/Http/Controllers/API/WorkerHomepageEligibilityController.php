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
        $depositSummary = $payload['depositSummary'] ?? null;

        if (is_array($eligibility) && is_array($depositSummary)) {
            $balanceEligible = (bool) ($depositSummary['isEligibleForNewRequests'] ?? false);

            $eligibility['canReceiveNewRequests'] = $balanceEligible;
            $eligibility['canAcceptNewBookings'] = $balanceEligible;
            $eligibility['reasonCode'] = $balanceEligible
                ? WorkerOrderSolvencyService::REASON_ELIGIBLE
                : WorkerOrderSolvencyService::REASON_INSUFFICIENT_COMMISSION_CAPACITY;
            $eligibility['message'] = $balanceEligible
                ? 'Your deposit balance can receive new requests.'
                : 'Your deposit balance is not enough to receive new requests. Please recharge your deposit account.';

            $payload['commissionCapacityEligibility'] = $eligibility;
        }

        return response()->json($payload, $response->getStatusCode(), $response->headers->all());
    }
}
