<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

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
            ->where(function (Builder $bookingQuery) use ($worker): void {
                $bookingQuery
                    ->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                        $assignments
                            ->where('worker_id', $worker->id)
                            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                    });
            })
            ->with([
                'customer',
                'workerAssignments' => function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                },
            ])
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id');

        $transactions = $query->paginate($perPage);

        $totalEarnings = (float) CleaningBooking::query()
            ->where(function (Builder $bookingQuery) use ($worker): void {
                $bookingQuery
                    ->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                        $assignments
                            ->where('worker_id', $worker->id)
                            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                    });
            })
            ->where('status', CleaningBookingStatus::Completed)
            ->with([
                'workerAssignments' => function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                },
            ])
            ->get()
            ->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));

        return response()->json([
            'summary' => [
                'totalTransactions' => $transactions->total(),
                'totalEarnings' => $totalEarnings,
            ],
            'data' => collect($transactions->items())
                ->map(fn (CleaningBooking $booking): array => $this->transactionRow($booking, $worker->id))
                ->values(),
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

    private function bookingWorkerAmount(CleaningBooking $booking, int $workerId): float
    {
        $assignment = $booking->relationLoaded('workerAssignments')
            ? $booking->workerAssignments->firstWhere('worker_id', $workerId)
            : null;

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            return (float) $assignment->worker_amount;
        }

        return max(0.0, round((float) ($booking->total_price ?? 0) - (float) ($booking->admin_margin_amount ?? 0), 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionRow(CleaningBooking $booking, int $workerId): array
    {
        /** @var CleaningBookingWorkerAssignment|null $assignment */
        $assignment = $booking->relationLoaded('workerAssignments')
            ? $booking->workerAssignments->first()
            : null;

        $workerAmount = $this->bookingWorkerAmount($booking, $workerId);

        return [
            'id' => $booking->id,
            'assignmentId' => $assignment?->id,
            'bookingNumber' => $booking->booking_number,
            'status' => $booking->status?->value,
            'assignmentStatus' => $assignment?->status?->value ?? 'legacy',
            'scheduledDate' => $booking->scheduled_date?->toDateString(),
            'scheduledTime' => $booking->scheduled_time,
            'totalPrice' => $workerAmount,
            'workerAmount' => $workerAmount,
            'bookingTotalPrice' => (float) ($booking->total_price ?? 0),
            'roomCount' => (int) ($assignment?->room_count ?? 1),
            'roomsWeight' => (float) ($assignment?->rooms_weight ?? 0),
            'adminMarginAmount' => (float) ($assignment?->admin_margin_amount ?? $booking->admin_margin_amount ?? 0),
            'currency' => $assignment?->currency ?? (string) config('app.currency', 'SYP'),
            'customer' => [
                'id' => $booking->customer?->id,
                'name' => $booking->customer?->name,
            ],
        ];
    }
}
