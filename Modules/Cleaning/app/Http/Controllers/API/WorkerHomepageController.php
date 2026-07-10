<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\GenderPreference;
use App\Enums\WorkerPreferredWorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class WorkerHomepageController
{
    private const ACCEPTED_ASSIGNMENT_STATUSES = ['accepted', 'accepted_waiting_team', 'accepted_waiting_for_order_start'];

    public function __construct(
        private readonly DepositService $depositService,
        private readonly WorkerOrderSolvencyService $solvencyService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $worker = auth()->user()?->worker;

        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6);
        $fourWeekStart = $weekStart->copy()->subWeeks(3);
        $fourWeekEnd = $weekEnd->copy();
        $dayLabels = [
            'monday' => 'الاثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
        ];

        if (! $worker) {
            return response()->json([
                'date' => $today->format('Y-m-d'),
                'totalBookings' => 0,
                'todayCount' => 0,
                'completedCount' => 0,
                'pendingCount' => 0,
                'confirmedCount' => 0,
                'inProgressCount' => 0,
                'cancelledCount' => 0,
                'totalEarnings' => 0,
                'todayEarnings' => 0,
                'earningsChangePercent' => 0,
                'newOrdersCount' => 0,
                'pendingExtensionRequestsCount' => 0,
                'amountSummary' => [
                    'period' => 'last_4_weeks',
                    'currency' => 'SYP',
                    'workerAmount' => 0.0,
                    'adminAmount' => 0.0,
                    'grossInvoicesAmount' => 0.0,
                    'depositAccountBalance' => 0.0,
                ],
                'isEligibleForNewRequests' => false,
                'depositSummary' => null,
                'dispatchEligibility' => null,
                'commissionCapacityEligibility' => null,
                'bookingsWeeklyChart' => $this->emptyBookingsWeeklyChart($weekStart, $dayLabels),
                'invoicesFourWeeksChart' => $this->emptyInvoicesFourWeeksChart($fourWeekStart),
            ]);
        }

        $baseQuery = CleaningBooking::query()->where(function (Builder $query) use ($worker): void {
            $query->where('worker_id', $worker->id)
                ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                    $assignments
                        ->where('worker_id', $worker->id)
                        ->whereIn('status', self::ACCEPTED_ASSIGNMENT_STATUSES);
                });
        });

        $totalBookings = (clone $baseQuery)->count();

        $todayCount = (clone $baseQuery)
            ->whereDate('scheduled_date', $today)
            ->whereNotIn('status', [CleaningBookingStatus::Cancelled])
            ->count();

        $completedCount = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->count();

        $pendingCount = (clone $baseQuery)
            ->whereIn('status', [
                CleaningBookingStatus::Pending,
                CleaningBookingStatus::WorkerAssigned,
            ])
            ->whereDate('scheduled_date', '>=', $today)
            ->count();

        $confirmedCount = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::WorkerAssigned)
            ->whereDate('scheduled_date', '>=', $today)
            ->count();

        $inProgressCount = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::InProgress)
            ->count();

        $cancelledCount = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Cancelled)
            ->count();

        $yesterday = $today->copy()->subDay();
        $completedBookings = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->with(['workerAssignments' => function (HasMany $assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id);
            }])
            ->get();

        $completedTodayBookings = $completedBookings->filter(
            fn (CleaningBooking $booking): bool => $booking->scheduled_date?->isSameDay($today) ?? false
        );
        $yesterdayBookings = $completedBookings->filter(
            fn (CleaningBooking $booking): bool => $booking->scheduled_date?->isSameDay($yesterday) ?? false
        );

        $totalEarnings = (float) $completedBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));

        $todayEarnings = (float) $completedTodayBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));
        $yesterdayEarnings = (float) $yesterdayBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));

        $earningsChangePercent = match (true) {
            $yesterdayEarnings > 0 => round((($todayEarnings - $yesterdayEarnings) / $yesterdayEarnings) * 100, 1),
            $todayEarnings > 0 => 100.0,
            default => 0.0,
        };

        $newOrderCandidates = $this->newOrdersCandidateQuery($worker, $today)->get();
        $newOrdersCount = 0;
        $blockedByCommissionCapacityCount = 0;

        foreach ($newOrderCandidates as $candidate) {
            if ($this->solvencyService->canWorkerReceiveBooking($worker, $candidate)) {
                $newOrdersCount++;

                continue;
            }

            $blockedByCommissionCapacityCount++;
        }

        $pendingExtensionRequestsCount = CleaningTimeWarning::query()
            ->where('booking_type', 'cleaning_booking')
            ->whereNull('worker_responded_at')
            ->whereHasMorph('booking', [CleaningBooking::class], function (Builder $q) use ($worker): void {
                $q->where(function (Builder $bookingQuery) use ($worker): void {
                    $bookingQuery
                        ->where('worker_id', $worker->id)
                        ->orWhereHas('workerAssignments', fn (Builder $assignments) => $assignments
                            ->where('worker_id', $worker->id)
                            ->whereIn('status', self::ACCEPTED_ASSIGNMENT_STATUSES));
                });
            })
            ->count();

        $completedFourWeeksBookings = (clone $baseQuery)
            ->where('status', CleaningBookingStatus::Completed)
            ->whereBetween('scheduled_date', [$fourWeekStart, $fourWeekEnd])
            ->with(['workerAssignments' => function (HasMany $assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id);
            }])
            ->get();

        $adminAmount = (float) $completedFourWeeksBookings->sum(fn (CleaningBooking $booking): float => $this->bookingAdminAmount($booking, $worker->id));
        $workerAmount = (float) $completedFourWeeksBookings->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));
        $grossInvoicesAmount = (float) $completedFourWeeksBookings->sum(fn (CleaningBooking $booking): float => $this->bookingGrossAmount($booking));

        $weeklyBookingsCounts = (clone $baseQuery)
            ->whereBetween('scheduled_date', [$weekStart, $weekEnd])
            ->where('status', '!=', CleaningBookingStatus::Cancelled)
            ->get(['scheduled_date'])
            ->groupBy(fn (CleaningBooking $booking): string => $booking->scheduled_date?->toDateString() ?? '')
            ->map(fn (Collection $bookings): int => $bookings->count());

        $bookingsWeeklyChart = [];
        $cursor = $weekStart->copy();
        while ($cursor->lte($weekEnd)) {
            $dateKey = $cursor->toDateString();
            $dayKey = mb_strtolower($cursor->englishDayOfWeek);
            $bookingsWeeklyChart[] = [
                'date' => $dateKey,
                'dayKey' => $dayKey,
                'dayLabelAr' => $dayLabels[$dayKey] ?? $cursor->englishDayOfWeek,
                'bookingsCount' => (int) ($weeklyBookingsCounts->get($dateKey) ?? 0),
            ];
            $cursor->addDay();
        }

        $invoicesFourWeeksChart = [];
        for ($index = 0; $index < 4; $index++) {
            $segmentStart = $fourWeekStart->copy()->addWeeks($index);
            $segmentEnd = $segmentStart->copy()->addDays(6);
            $segmentSum = (float) $completedFourWeeksBookings
                ->filter(fn (CleaningBooking $booking): bool => $booking->scheduled_date !== null
                    && $booking->scheduled_date->betweenIncluded($segmentStart, $segmentEnd))
                ->sum(fn (CleaningBooking $booking): float => $this->bookingWorkerAmount($booking, $worker->id));

            $invoicesFourWeeksChart[] = [
                'weekNumber' => $index + 1,
                'label' => 'week_'.($index + 1),
                'from' => $segmentStart->toDateString(),
                'to' => $segmentEnd->toDateString(),
                'invoiceAmount' => round($segmentSum, 2),
                'invoiceAmountThousands' => round($segmentSum / 1000, 3),
            ];
        }

        $worker->loadMissing('deposit');
        $depositSummary = $this->depositService->depositStatusPayload($worker);
        $dispatchEligibility = $this->newRequestEligibility($worker, $depositSummary);
        $commissionCapacityEligibility = $this->commissionCapacityEligibility(
            $worker,
            $depositSummary,
            $newOrdersCount,
            $blockedByCommissionCapacityCount,
        );
        $canReceiveNewRequests = (bool) $dispatchEligibility['canReceiveNewRequests'];

        return response()->json([
            'date' => $today->format('Y-m-d'),
            'totalBookings' => $totalBookings,
            'todayCount' => $todayCount,
            'completedCount' => $completedCount,
            'pendingCount' => $pendingCount,
            'confirmedCount' => $confirmedCount,
            'inProgressCount' => $inProgressCount,
            'cancelledCount' => $cancelledCount,
            'totalEarnings' => $totalEarnings,
            'todayEarnings' => $todayEarnings,
            'earningsChangePercent' => $earningsChangePercent,
            'newOrdersCount' => $canReceiveNewRequests ? $newOrdersCount : 0,
            'pendingExtensionRequestsCount' => $pendingExtensionRequestsCount,
            'isEligibleForNewRequests' => $canReceiveNewRequests,
            'depositSummary' => $depositSummary,
            'dispatchEligibility' => $dispatchEligibility,
            'commissionCapacityEligibility' => $commissionCapacityEligibility,
            'amountSummary' => [
                'period' => 'last_4_weeks',
                'currency' => 'SYP',
                'workerAmount' => round($workerAmount, 2),
                'adminAmount' => round($adminAmount, 2),
                'grossInvoicesAmount' => round($grossInvoicesAmount, 2),
                'depositAccountBalance' => $depositSummary['currentBalance'],
            ],
            'bookingsWeeklyChart' => $bookingsWeeklyChart,
            'invoicesFourWeeksChart' => $invoicesFourWeeksChart,
        ]);
    }

    private function newOrdersCandidateQuery(object $worker, Carbon $today): Builder
    {
        return CleaningBooking::query()
            ->where('status', CleaningBookingStatus::Pending)
            ->whereDate('scheduled_date', '>=', $today)
            ->where(fn ($q) => $q->whereNull('worker_id')->orWhere('worker_id', $worker->id))
            ->when(
                $this->preferredWorkType($worker) === WorkerPreferredWorkType::Cleaning,
                fn (Builder $query): Builder => $query->where('property_type', '!=', UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
            )
            ->when(
                $this->preferredWorkType($worker) === WorkerPreferredWorkType::Events,
                fn (Builder $query): Builder => $query->where('property_type', UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
            )
            ->whereDoesntHave('workerAssignments', fn (Builder $assignments) => $assignments
                ->where('worker_id', $worker->id)
                ->whereIn('status', self::ACCEPTED_ASSIGNMENT_STATUSES))
            ->where(function ($genderQuery) use ($worker): void {
                $genderQuery
                    ->whereNull('gender_preference')
                    ->orWhere('gender_preference', GenderPreference::Any->value)
                    ->orWhere('gender_preference', $worker->gender);
            })
            ->whereDoesntHave('rejections', fn ($q) => $q->where('worker_id', $worker->id));
    }

    private function emptyBookingsWeeklyChart(Carbon $weekStart, array $dayLabels): array
    {
        $rows = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $dayKey = mb_strtolower($date->englishDayOfWeek);
            $rows[] = [
                'date' => $date->toDateString(),
                'dayKey' => $dayKey,
                'dayLabelAr' => $dayLabels[$dayKey] ?? $date->englishDayOfWeek,
                'bookingsCount' => 0,
            ];
        }

        return $rows;
    }

    private function emptyInvoicesFourWeeksChart(Carbon $fourWeekStart): array
    {
        $rows = [];

        for ($index = 0; $index < 4; $index++) {
            $segmentStart = $fourWeekStart->copy()->addWeeks($index);
            $segmentEnd = $segmentStart->copy()->addDays(6);

            $rows[] = [
                'weekNumber' => $index + 1,
                'label' => 'week_'.($index + 1),
                'from' => $segmentStart->toDateString(),
                'to' => $segmentEnd->toDateString(),
                'invoiceAmount' => 0.0,
                'invoiceAmountThousands' => 0.0,
            ];
        }

        return $rows;
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

    private function preferredWorkType(object $worker): WorkerPreferredWorkType
    {
        return $worker->preferred_work_type instanceof WorkerPreferredWorkType
            ? $worker->preferred_work_type
            : WorkerPreferredWorkType::tryFrom((string) ($worker->preferred_work_type ?? WorkerPreferredWorkType::Both->value)) ?? WorkerPreferredWorkType::Both;
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

    private function bookingGrossAmount(CleaningBooking $booking): float
    {
        return (float) ($booking->total_price ?? 0);
    }

    private function newRequestEligibility(object $worker, array $depositSummary): array
    {
        $canReceive = (bool) ($depositSummary['isEligibleForNewRequests'] ?? false);
        $reasonCode = $this->eligibilityReasonCode($worker, $depositSummary, $canReceive);

        return [
            'canReceiveNewRequests' => $canReceive,
            'canAcceptNewBookings' => $canReceive,
            'reasonCode' => $reasonCode,
            'message' => $this->eligibilityMessage($reasonCode),
            'depositSummary' => $depositSummary,
        ];
    }

    private function commissionCapacityEligibility(object $worker, array $depositSummary, int $availableNewOrdersCount, int $blockedNewOrdersCount): array
    {
        $capacitySummary = $this->solvencyService->workerCapacitySummary($worker);
        $hasBlockedOrders = $blockedNewOrdersCount > 0;
        $reasonCode = $hasBlockedOrders
            ? WorkerOrderSolvencyService::REASON_INSUFFICIENT_COMMISSION_CAPACITY
            : WorkerOrderSolvencyService::REASON_ELIGIBLE;

        return [
            'canReceiveNewRequests' => ! $hasBlockedOrders,
            'canAcceptNewBookings' => ! $hasBlockedOrders,
            'reasonCode' => $reasonCode,
            'message' => $this->commissionCapacityMessage($reasonCode),
            'depositSummary' => array_merge($depositSummary, $capacitySummary),
            'availableNewOrdersCount' => $availableNewOrdersCount,
            'blockedNewOrdersCount' => $blockedNewOrdersCount,
        ];
    }

    private function commissionCapacityMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            WorkerOrderSolvencyService::REASON_INSUFFICIENT_COMMISSION_CAPACITY => 'Your available commission capacity is not enough to receive some new requests. Please recharge your deposit account or wait until reserved commissions are released.',
            default => 'Your available commission capacity can receive new requests.',
        };
    }

    private function eligibilityReasonCode(object $worker, array $depositSummary, bool $canReceive): string
    {
        if (! $worker->is_active) {
            return 'worker_inactive';
        }

        if ($worker->is_suspended) {
            return 'worker_suspended';
        }

        if ($canReceive) {
            return 'eligible';
        }

        if (($depositSummary['exceedanceAmount'] ?? null) !== null) {
            return 'deposit_below_allowed_balance';
        }

        return 'trust_score_too_low';
    }

    private function eligibilityMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'eligible' => 'Account can receive and accept new requests.',
            'worker_inactive' => 'Account is inactive.',
            'worker_suspended' => 'Account status prevents new requests.',
            'deposit_below_allowed_balance' => 'Deposit balance is below the allowed limit.',
            'trust_score_too_low' => 'Trust score is below the required minimum.',
            default => 'Account cannot receive new requests right now.',
        };
    }
}
