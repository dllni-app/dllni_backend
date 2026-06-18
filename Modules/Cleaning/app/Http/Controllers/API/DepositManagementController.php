<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\CleaningDepositSetting;
use App\Models\Worker;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Resources\CleaningDepositTransactionResource;
use Modules\Cleaning\Services\DepositService;

final class DepositManagementController
{
    public function __construct(
        private readonly DepositService $depositService
    ) {}

    public function recordDeposit(Request $request, Worker $worker): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $transaction = $this->depositService->recordDeposit(
                $worker,
                (float) $validated['amount'],
                $validated['reference'],
                $validated['notes'] ?? null,
                auth()->id(),
            );

            return response()->json([
                'message' => 'Deposit recorded successfully',
                'transaction' => CleaningDepositTransactionResource::make($transaction),
            ], Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function recordWithdrawal(Request $request, Worker $worker): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $transaction = $this->depositService->recordWithdrawal(
                $worker,
                (float) $validated['amount'],
                $validated['reference'],
                $validated['notes'] ?? null,
                auth()->id(),
            );

            return response()->json([
                'message' => 'Withdrawal recorded successfully',
                'transaction' => CleaningDepositTransactionResource::make($transaction),
            ], Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function getWorkerTransactions(Request $request, Worker $worker): JsonResponse
    {
        $perPage = (int) $request->integer('perPage', 20);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 20;
        }

        $type = $request->get('type');

        $query = \App\Models\CleaningDepositTransaction::where('worker_id', $worker->id);

        if ($type && in_array($type, ['deposit', 'withdrawal', 'admin_fee'], true)) {
            $query->where('type', $type);
        }

        $transactions = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => CleaningDepositTransactionResource::collection($transactions)->collection,
            'meta' => [
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'perPage' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function getSettings(): JsonResponse
    {
        $settings = $this->resolveSettings();

        return response()->json($this->settingsPayload($settings));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'minimum_deposit_amount' => 'nullable|numeric|min:0',
            'default_max_negative_balance' => 'nullable|numeric|min:0',
            'trust_reject_after_accept_penalty' => 'nullable|integer|min:0',
            'trust_minimum_for_dispatch' => 'nullable|integer|min:0|max:100',
            'is_enabled' => 'nullable|boolean',
        ]);

        $settings = $this->resolveSettings();

        $settings->update(array_filter([
            'minimum_deposit_amount' => $validated['minimum_deposit_amount'] ?? null,
            'default_max_negative_balance' => $validated['default_max_negative_balance'] ?? null,
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

    private function resolveSettings(): CleaningDepositSetting
    {
        return CleaningDepositSetting::query()->firstOrCreate([], [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 0,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => (int) config('cleaning.trust.reject_after_accept_penalty', 10),
            'trust_minimum_for_dispatch' => 0,
        ]);
    }

    /**
     * @return array<string, bool|float|int>
     */
    private function settingsPayload(CleaningDepositSetting $settings): array
    {
        return [
            'minimumDepositAmount' => (float) $settings->minimum_deposit_amount,
            'defaultMaxNegativeBalance' => (float) $settings->default_max_negative_balance,
            'trustRejectAfterAcceptPenalty' => (int) $settings->trust_reject_after_accept_penalty,
            'trustMinimumForDispatch' => (int) $settings->trust_minimum_for_dispatch,
            'isEnabled' => (bool) $settings->is_enabled,
        ];
    }
}
