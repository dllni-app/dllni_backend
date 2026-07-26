<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\WorkerDispatchEligibilityService;
use Modules\Cleaning\Services\WorkerFinancialAccountStatusService;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;

final class WorkerHomepageEligibilityController
{
    private const REASON_FINANCIAL_ACCOUNT_INACTIVE = 'financial_account_inactive';

    public function __construct(
        private readonly WorkerHomepageController $homepageController,
        private readonly WorkerFinancialAccountStatusService $financialAccountStatusService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $response = ($this->homepageController)($request);
        $payload = $response->getData(true);
        $worker = auth()->user()?->worker;

        if ($worker !== null && ! $this->financialAccountStatusService->isFinancialAccountActive($worker)) {
            $depositSummary = is_array($payload['depositSummary'] ?? null)
                ? $payload['depositSummary']
                : [];
            $depositSummary = array_merge($depositSummary, [
                'status' => WorkerFinancialAccountStatusService::INACTIVE,
                'isEligibleForNewRequests' => false,
                'isFinancialAccountActive' => false,
                'isActive' => false,
            ]);

            $message = 'The insurance deposit account was closed after the full balance was withdrawn. Add a new deposit to receive requests again.';
            $eligibility = [
                'canReceiveNewRequests' => false,
                'canAcceptNewBookings' => false,
                'canStartAssignedWork' => false,
                'status' => self::REASON_FINANCIAL_ACCOUNT_INACTIVE,
                'reasonCode' => self::REASON_FINANCIAL_ACCOUNT_INACTIVE,
                'startWorkReasonCode' => self::REASON_FINANCIAL_ACCOUNT_INACTIVE,
                'title' => 'Insurance deposit account is inactive',
                'message' => $message,
                'action' => [
                    'type' => 'open_deposit',
                    'label' => 'View financial account',
                ],
                'depositSummary' => $depositSummary,
            ];

            $payload['isEligibleForNewRequests'] = false;
            $payload['depositSummary'] = $depositSummary;
            $payload['dispatchEligibility'] = array_merge(
                is_array($payload['dispatchEligibility'] ?? null) ? $payload['dispatchEligibility'] : [],
                $eligibility,
            );
            $payload['commissionCapacityEligibility'] = array_merge(
                is_array($payload['commissionCapacityEligibility'] ?? null) ? $payload['commissionCapacityEligibility'] : [],
                $eligibility,
            );
            $payload['newOrdersCount'] = 0;

            return response()->json($payload, $response->getStatusCode(), $response->headers->all());
        }

        $eligibility = $payload['commissionCapacityEligibility'] ?? null;
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
                $commissionEligible = (bool) ($eligibility['canReceiveNewRequests'] ?? false);
                $availableNewOrdersCount = max(0, (int) ($eligibility['availableNewOrdersCount'] ?? 0));
                $hasAffordableNewOrder = $availableNewOrdersCount > 0;
                $canReceiveNewRequests = $accountEligible && ($commissionEligible || $hasAffordableNewOrder);

                $eligibility['canReceiveNewRequests'] = $canReceiveNewRequests;
                $eligibility['canAcceptNewBookings'] = $canReceiveNewRequests;

                if (! $accountEligible) {
                    $eligibility['reasonCode'] = WorkerOrderSolvencyService::REASON_INSUFFICIENT_COMMISSION_CAPACITY;
                    $eligibility['message'] = 'Your worker debt exceeds the allowed limit. Settle the excess debt before receiving new requests.';
                } elseif ($canReceiveNewRequests) {
                    $eligibility['reasonCode'] = WorkerOrderSolvencyService::REASON_ELIGIBLE;
                    $eligibility['message'] = 'Your available commission capacity can receive new requests.';
                } else {
                    $eligibility['reasonCode'] = WorkerOrderSolvencyService::REASON_INSUFFICIENT_COMMISSION_CAPACITY;
                    $eligibility['message'] = 'Your available commission capacity is not enough for the currently available requests.';
                }
            }

            $payload['commissionCapacityEligibility'] = $eligibility;
        }

        return response()->json($payload, $response->getStatusCode(), $response->headers->all());
    }
}
