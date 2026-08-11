<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Delivery\Enums\DeliveryAssignmentAttemptStatus;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\FinancialLedgerService;

final class DriverDashboardSummaryController
{
    public function __construct(private readonly FinancialLedgerService $ledgerService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $account = $this->ledgerService->ensureAccount($driver, 'SYP');
        $today = now()->toDateString();

        $activeOrdersCount = DeliveryOrder::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', [
                DeliveryOrderStatus::Accepted->value,
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
            ])
            ->count();

        $completedTodayCount = DeliveryOrder::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', [DeliveryOrderStatus::Delivered->value, DeliveryOrderStatus::Completed->value])
            ->whereDate('updated_at', $today)
            ->count();

        $rejectedOffersTodayCount = DeliveryAssignmentAttempt::query()
            ->where('driver_id', $driver->id)
            ->where('status', DeliveryAssignmentAttemptStatus::Rejected->value)
            ->whereDate('updated_at', $today)
            ->count();

        $missedOffersTodayCount = DeliveryAssignmentAttempt::query()
            ->where('driver_id', $driver->id)
            ->where('status', DeliveryAssignmentAttemptStatus::TimedOut->value)
            ->whereDate('updated_at', $today)
            ->count();

        return response()->json([
            'data' => [
                'activeOrdersCount' => $activeOrdersCount,
                'completedTodayCount' => $completedTodayCount,
                'rejectedOffersTodayCount' => $rejectedOffersTodayCount,
                'missedOffersTodayCount' => $missedOffersTodayCount,
                'currentBalance' => (float) data_get($account, 'current_balance', 0),
                'currency' => (string) data_get($account, 'currency', 'SYP'),
                'availabilityStatus' => (string) $driver->availability_status,
            ],
        ]);
    }

    private function driver(Request $request): DeliveryDriver
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        return $driver;
    }
}
