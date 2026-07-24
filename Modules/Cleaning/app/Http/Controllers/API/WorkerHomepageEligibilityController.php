<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\WorkerDispatchEligibilityService;
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
        $eligibility = $payload['administrationCapacityEligibility'] ?? null;
        $depositSummary = $payload['depositSummary'] ?? null;
        $dispatchEligibility = $payload['dispatchEligibility'] ?? null;

        if (is_array($eligibility) && is_array($depositSummary)) {
            $accountReasonCode = is_array($dispatchEligibility)
                ? ($dispatchEligibility['reasonCode'] ?? null)
                : null;

            if (in_array($accountReasonCode, [
                WorkerDispatchEligibilityService::REASON_WORKER_INACTIVE,
                WorkerDispatchEligibilityService::REASON_WORKER_SUSPENDED,
            ], true)) {
                $eligibility['canReceiveNewRequests'] = false;
                $eligibility['canAcceptNewBookings'] = false;
                $eligibility['reasonCode'] = $accountReasonCode;
                $eligibility['message'] = $dispatchEligibility['message']
                    ?? 'Your worker account cannot receive new orders right now.';
            } else {
                $accountEligible = (bool) ($depositSummary['isEligibleForNewRequests'] ?? false);
                $capacityEligible = (bool) ($eligibility['canReceiveNewRequests'] ?? false);
                $availableNewOrdersCount = max(0, (int) ($eligibility['availableNewOrdersCount'] ?? 0));
                $hasAffordableNewOrder = $availableNewOrdersCount > 0;
                $canReceiveNewRequests = $accountEligible && ($capacityEligible || $hasAffordableNewOrder);

                $eligibility['canReceiveNewRequests'] = $canReceiveNewRequests;
                $eligibility['canAcceptNewBookings'] = $canReceiveNewRequests;

                if (! $accountEligible) {
                    $eligibility['reasonCode'] = WorkerOrderSolvencyService::REASON_INSUFFICIENT_ADMINISTRATION_CAPACITY;
                    $eligibility['message'] = 'Your worker debt exceeds the allowed limit. Settle the excess debt before receiving new requests.';
                } elseif ($canReceiveNewRequests) {
                    $eligibility['reasonCode'] = WorkerOrderSolvencyService::REASON_ELIGIBLE;
                    $eligibility['message'] = 'Your available administration capacity can receive new requests.';
                } else {
                    $eligibility['reasonCode'] = WorkerOrderSolvencyService::REASON_INSUFFICIENT_ADMINISTRATION_CAPACITY;
                    $eligibility['message'] = 'Your available administration capacity is not enough for the currently available requests.';
                }
            }

            $payload['administrationCapacityEligibility'] = $eligibility;
        }

        return response()->json($payload, $response->getStatusCode(), $response->headers->all());
    }
}
