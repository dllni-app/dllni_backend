<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Resources\CleaningDepositTransactionResource;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Modules\Cleaning\Services\DepositService;

final class DepositManagementController
{
    public function __construct(
        private readonly DepositService $depositService,
        private readonly AdminCleaningTransactionService $transactionService,
    ) {}

    public function recordDeposit(Request $request, Worker $worker): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        return $this->runTransaction(fn () => $this->depositService->recordDeposit(
            $worker,
            (float) $validated['amount'],
            $validated['reference'] ?? 'admin_api_deposit',
            $validated['notes'] ?? null,
            auth()->id(),
        ), 'Deposit recorded successfully');
    }

    public function recordDebt(Request $request, Worker $worker): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'required|string|max:1000',
        ]);

        return $this->runTransaction(fn () => $this->transactionService->create(
            worker: $worker,
            type: 'debt',
            amount: (float) $validated['amount'],
            notes: $validated['notes'],
            createdByAdminId: auth()->id(),
        ), 'Debt recorded successfully');
    }

    public function settleFullDebt(Request $request, Worker $worker): JsonResponse
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:1000']);

        return $this->runTransaction(fn () => $this->transactionService->settleFullDebt(
            worker: $worker,
            notes: $validated['notes'] ?? null,
            createdByAdminId: auth()->id(),
        ), 'Debt settled successfully');
    }

    /** @deprecated Use recordRefund(). */
    public function recordWithdrawal(Request $request, Worker $worker): JsonResponse
    {
        return $this->recordRefund($request, $worker);
    }

    public function recordRefund(Request $request, Worker $worker): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        return $this->runTransaction(fn () => $this->transactionService->refundFullBalance(
            worker: $worker,
            notes: $validated['notes'] ?? null,
            createdByAdminId: auth()->id(),
        ), 'Full financial account refund recorded successfully');
    }

    public function getWorkerTransactions(Request $request, Worker $worker): JsonResponse
    {
        return $this->transactionsResponse($request, $worker);
    }

    public function getTransactions(Request $request, Worker $worker): JsonResponse
    {
        return $this->transactionsResponse($request, $worker);
    }

    public function getSettings(): JsonResponse
    {
        return response()->json($this->settingsPayload($this->resolveSettings()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'allowed_debt_limit' => 'nullable|numeric|min:0',
            'default_max_negative_balance' => 'nullable|numeric|min:0',
            'minimum_deposit_amount' => 'nullable|numeric|min:0',
            'restriction_threshold_percent' => 'nullable|numeric|min:0|max:100',
            'trust_reject_after_accept_penalty' => 'nullable|integer|min:0',
            'trust_minimum_for_dispatch' => 'nullable|integer|min:0|max:100',
            'is_enabled' => 'nullable|boolean',
        ]);

        $allowedDebtLimit = $validated['allowed_debt_limit'] ?? $validated['default_max_negative_balance'] ?? null;
        $settings = $this->resolveSettings();
        $settings->update(array_filter([
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => $allowedDebtLimit,
            'restriction_threshold_percent' => 100,
            'trust_reject_after_accept_penalty' => $validated['trust_reject_after_accept_penalty'] ?? null,
            'trust_minimum_for_dispatch' => $validated['trust_minimum_for_dispatch'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? null,
        ], static fn ($value) => $value !== null));

        $this->depositService->syncAllWorkerDepositStatuses();

        return response()->json([
            'message' => 'Settings updated successfully',
            ...$this->settingsPayload($settings->fresh()),
        ]);
    }

    private function transactionsResponse(Request $request, Worker $worker): JsonResponse
    {
        $perPage = (int) $request->integer('perPage', 20);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 20;
        }

        $type = $request->string('type')->toString();
        $transactions = CleaningDepositTransaction::query()
            ->where('worker_id', $worker->id)
            ->publiclyVisible()
            ->when(in_array($type, CleaningDepositTransaction::PUBLIC_TYPES, true), fn ($query) => $query->forPublicType($type))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => CleaningDepositTransactionResource::collection($transactions)->collection,
            'meta' => [
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'perPage' => $transactions->perPage(),
                'total' => $transactions->total(),
                'filters' => ['type' => in_array($type, CleaningDepositTransaction::PUBLIC_TYPES, true) ? $type : null],
            ],
        ]);
    }

    private function resolveSettings(): CleaningDepositSetting
    {
        return CleaningDepositSetting::query()->firstOrCreate([], [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 0,
            'restriction_threshold_percent' => 100,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => (int) config('cleaning.trust.reject_after_accept_penalty', 10),
            'trust_minimum_for_dispatch' => 0,
        ]);
    }

    private function settingsPayload(CleaningDepositSetting $settings): array
    {
        $allowedDebtLimit = max(0.0, (float) $settings->default_max_negative_balance);

        return [
            'minimumDepositAmount' => 0.0,
            'allowedDebtLimit' => $allowedDebtLimit,
            'defaultMaxNegativeBalance' => $allowedDebtLimit,
            'restrictionThresholdPercent' => 100.0,
            'trustRejectAfterAcceptPenalty' => (int) $settings->trust_reject_after_accept_penalty,
            'trustMinimumForDispatch' => (int) $settings->trust_minimum_for_dispatch,
            'isEnabled' => (bool) $settings->is_enabled,
        ];
    }

    private function runTransaction(callable $callback, string $message): JsonResponse
    {
        try {
            $transaction = $callback();

            return response()->json([
                'message' => $message,
                'transaction' => CleaningDepositTransactionResource::make($transaction),
            ], Response::HTTP_CREATED);
        } catch (Exception $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
