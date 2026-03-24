<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class WorkerTransactionsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $perPage = (int) $request->integer('perPage', 10);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 10;
        }

        $query = CleaningBooking::query()
            ->where('worker_id', $worker->id)
            ->with('customer')
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');

        $transactions = $query->paginate($perPage);

        $totalEarnings = (float) CleaningBooking::query()
            ->where('worker_id', $worker->id)
            ->where('status', CleaningBookingStatus::Completed)
            ->sum('total_price');

        return response()->json([
            'summary' => [
                'totalTransactions' => $transactions->total(),
                'totalEarnings' => $totalEarnings,
            ],
            'data' => collect($transactions->items())->map(static fn (CleaningBooking $booking): array => [
                'id' => $booking->id,
                'bookingNumber' => $booking->booking_number,
                'status' => $booking->status?->value,
                'scheduledDate' => $booking->scheduled_date?->toDateString(),
                'scheduledTime' => $booking->scheduled_time,
                'totalPrice' => (float) $booking->total_price,
                'customer' => [
                    'id' => $booking->customer?->id,
                    'name' => $booking->customer?->name,
                ],
            ])->values(),
            'meta' => [
                'currentPage' => $transactions->currentPage(),
                'lastPage' => $transactions->lastPage(),
                'perPage' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    private function worker(): ?Worker
    {
        return auth()->user()?->worker;
    }
}
