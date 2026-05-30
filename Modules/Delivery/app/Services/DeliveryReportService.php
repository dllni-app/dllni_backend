<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Models\Dispute;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliveryFinancialTransactionType;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Delivery\Models\DeliveryOrder;

final class DeliveryReportService
{
    public function __construct(
        private readonly FinancialLedgerService $ledgerService,
    ) {}

    /**
     * @return array{
     *     statusCounts: array<string, int>,
     *     completedPerDay: array<string, int>,
     *     driverAvailability: array<string, int>,
     *     financial: array<string, float|string|null>,
     *     disputesCount: int,
     *     openDisputesCount: int,
     *     periodDays: int,
     * }
     */
    public function summary(DeliveryCompany $company, int $days = 30): array
    {
        $days = max(7, min($days, 90));
        $from = now()->subDays($days - 1)->startOfDay();
        $companyId = $company->id;

        $statusCounts = DeliveryOrder::query()
            ->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $completedPerDay = DeliveryOrder::query()
            ->where('company_id', $companyId)
            ->where('status', DeliveryOrderStatus::Completed->value)
            ->where('completed_at', '>=', $from)
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $driversQuery = DeliveryDriver::query()->where('company_id', $companyId);

        $driverAvailability = [
            'available' => (clone $driversQuery)->where('availability_status', DeliveryDriverAvailabilityStatus::Available->value)->count(),
            'busy' => (clone $driversQuery)->where('availability_status', DeliveryDriverAvailabilityStatus::Busy->value)->count(),
            'offline' => (clone $driversQuery)->where('availability_status', DeliveryDriverAvailabilityStatus::Offline->value)->count(),
            'suspended' => (clone $driversQuery)->where('is_suspended', true)->count(),
            'total' => (clone $driversQuery)->count(),
        ];

        $account = $this->ledgerService->accountForCompany($company);

        $feesInPeriod = (float) DeliveryFinancialTransaction::query()
            ->where('account_id', $account->id)
            ->where('transaction_type', DeliveryFinancialTransactionType::OrderFeeDebit->value)
            ->where('created_at', '>=', $from)
            ->sum('amount');

        $orderIds = DeliveryOrder::query()
            ->where('company_id', $companyId)
            ->select('id');

        $disputesCount = Dispute::query()
            ->where('booking_type', 'delivery_order')
            ->whereIn('booking_id', $orderIds)
            ->count();

        $openDisputesCount = Dispute::query()
            ->where('booking_type', 'delivery_order')
            ->whereIn('booking_id', $orderIds)
            ->whereIn('status', ['open', 'under_review'])
            ->count();

        return [
            'statusCounts' => $statusCounts,
            'completedPerDay' => $completedPerDay,
            'driverAvailability' => $driverAvailability,
            'financial' => [
                'currentBalance' => (float) $account->current_balance,
                'financialLimit' => (float) $account->financial_limit,
                'currency' => $account->currency,
                'feesInPeriod' => $feesInPeriod,
                'isSuspended' => (bool) $account->is_suspended,
            ],
            'disputesCount' => $disputesCount,
            'openDisputesCount' => $openDisputesCount,
            'periodDays' => $days,
        ];
    }
}
