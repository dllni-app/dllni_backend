<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class WorkerStatisticsSummaryService
{
    private const ACCEPTED_ASSIGNMENT_STATUSES = [
        'accepted',
        'accepted_waiting_team',
        'accepted_waiting_for_order_start',
    ];

    public function __construct(
        private readonly AdminCleaningTransactionService $transactionService,
    ) {}

    /**
     * Return the same financial figures displayed on the worker statistics page.
     *
     * @return array{
     *     grossInvoicesAmount: float,
     *     workerAmount: float,
     *     adminAmount: float,
     *     completedCount: int,
     *     manualDebtAmount: float,
     *     currentBalance: float,
     *     minimumRequired: float,
     *     depositedTotal: float,
     *     withdrawnTotal: float,
     *     status: string
     * }
     */
    public function summary(Worker $worker): array
    {
        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $fourWeekStart = $weekStart->copy()->subWeeks(3);
        $fourWeekEnd = $weekStart->copy()->addDays(6);

        $baseQuery = CleaningBooking::query()
            ->where(function (Builder $query) use ($worker): void {
                $query
                    ->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                        $assignments
                            ->where('worker_id', $worker->id)
                            ->whereIn('status', self::ACCEPTED_ASSIGNMENT_STATUSES);
                    });
            });

        $completedCount = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->count();

        $completedFourWeeksBookings = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->whereBetween('scheduled_date', [$fourWeekStart, $fourWeekEnd])
            ->with([
                'workerAssignments' => function (HasMany $assignments) use ($worker): void {
                    $assignments->where('worker_id', $worker->id);
                },
            ])
            ->get();

        $workerAmount = (float) $completedFourWeeksBookings->sum(
            fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id),
        );
        $adminAmount = (float) $completedFourWeeksBookings->sum(
            fn (CleaningBooking $booking): float => $this->bookingAdminAmount($booking, $worker->id),
        );
        $grossInvoicesAmount = (float) $completedFourWeeksBookings->sum(
            fn (CleaningBooking $booking): float => (float) ($booking->total_price ?? 0),
        );

        $financial = $this->transactionService->snapshot($worker);

        return [
            'grossInvoicesAmount' => round($grossInvoicesAmount, 2),
            'workerAmount' => round($workerAmount, 2),
            'adminAmount' => round($adminAmount, 2),
            'completedCount' => $completedCount,
            'manualDebtAmount' => round((float) ($financial['adminLoanBalance'] ?? 0), 2),
            'currentBalance' => round((float) ($financial['currentBalance'] ?? 0), 2),
            'minimumRequired' => round((float) ($financial['minimumRequired'] ?? 0), 2),
            'depositedTotal' => round((float) ($financial['depositedTotal'] ?? 0), 2),
            'withdrawnTotal' => round((float) ($financial['withdrawnTotal'] ?? 0), 2),
            'status' => (string) ($financial['status'] ?? 'inactive'),
        ];
    }

    private function bookingWorkerAmount(CleaningBooking $booking, int $workerId): float
    {
        $assignment = $booking->relationLoaded('workerAssignments')
            ? $booking->workerAssignments->firstWhere('worker_id', $workerId)
            : null;

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            return (float) $assignment->worker_amount;
        }

        return max(
            0.0,
            round(
                (float) ($booking->total_price ?? 0) - (float) ($booking->admin_margin_amount ?? 0),
                2,
            ),
        );
    }

    private function bookingAdminAmount(CleaningBooking $booking, int $workerId): float
    {
        $assignment = $booking->relationLoaded('workerAssignments')
            ? $booking->workerAssignments->firstWhere('worker_id', $workerId)
            : null;

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            return (float) $assignment->admin_margin_amount;
        }

        return (float) ($booking->admin_margin_amount ?? 0);
    }
}
