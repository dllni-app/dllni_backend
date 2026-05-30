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
                $validated['notes'] ?? null
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
                $validated['notes'] ?? null
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

        if ($type && in_array($type, ['deposit', 'withdrawal'])) {
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
        $settings = CleaningDepositSetting::first();

        if (! $settings) {
            $settings = CleaningDepositSetting::create([
                'minimum_deposit_amount' => 0,
                'is_enabled' => true,
            ]);
        }

        return response()->json([
            'minimumDepositAmount' => (float) $settings->minimum_deposit_amount,
            'isEnabled' => $settings->is_enabled,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'minimum_deposit_amount' => 'nullable|numeric|min:0',
            'is_enabled' => 'nullable|boolean',
        ]);

        $settings = CleaningDepositSetting::firstOrCreate([], [
            'minimum_deposit_amount' => 0,
            'is_enabled' => true,
        ]);

        $settings->update(array_filter([
            'minimum_deposit_amount' => $validated['minimum_deposit_amount'] ?? null,
            'is_enabled' => $validated['is_enabled'] ?? null,
        ], static fn ($v) => $v !== null));

        return response()->json([
            'message' => 'Settings updated successfully',
            'minimumDepositAmount' => (float) $settings->minimum_deposit_amount,
            'isEnabled' => $settings->is_enabled,
        ]);
    }
}
