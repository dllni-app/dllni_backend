<?php

declare(strict_types=1);

namespace Modules\Delivery\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Throwable;

final class BackfillMerchantDeliveryStateCommand extends Command
{
    protected $signature = 'delivery:backfill-merchant-dispatch {--dry-run : Report changes without writing or dispatching}';

    protected $description = 'Backfill merchant readiness metadata and resume or pause linked restaurant/supermarket delivery searches safely.';

    public function handle(DeliveryOrderService $deliveries): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $started = 0;
        $paused = 0;
        $cancelled = 0;
        $missingSources = 0;

        DeliveryOrder::query()
            ->whereIn('source_type', ['restaurant_order', 'supermarket_order'])
            ->whereNotNull('source_id')
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (
                $deliveries,
                $dryRun,
                &$updated,
                &$started,
                &$paused,
                &$cancelled,
                &$missingSources,
            ): void {
                foreach ($orders as $deliveryOrder) {
                    $source = $deliveryOrder->source()->first();
                    if (! $source instanceof Order && ! $source instanceof SmOrder) {
                        $missingSources++;
                        continue;
                    }

                    $sourceStatus = $source->status?->value ?? (string) $source->status;
                    $acceptedAt = $source->accepted_at;
                    $estimatedMinutes = $source->estimated_preparation_minutes;
                    $estimatedReadyAt = $source->estimated_ready_at;
                    $readyAt = $source->ready_for_pickup_at;

                    $isPending = $sourceStatus === ($source instanceof Order
                        ? OrderStatus::Pending->value
                        : SmOrderStatus::Pending->value);
                    $isCancelled = $sourceStatus === ($source instanceof Order
                        ? OrderStatus::Cancelled->value
                        : SmOrderStatus::Cancelled->value);
                    $isDispatchable = in_array($sourceStatus, $source instanceof Order
                        ? [OrderStatus::Accepted->value, OrderStatus::Preparing->value, OrderStatus::ReadyForPickup->value]
                        : [SmOrderStatus::Accepted->value, SmOrderStatus::Preparing->value, SmOrderStatus::ReadyForPickup->value], true);

                    if ($dryRun) {
                        $updated++;
                        if ($isPending && $deliveryOrder->driver_id === null && $this->isSearching($deliveryOrder)) {
                            $paused++;
                        } elseif ($isCancelled && ! $this->isTerminal($deliveryOrder)) {
                            $cancelled++;
                        } elseif ($isDispatchable && $deliveryOrder->driver_id === null && $this->needsFreshSearch($deliveryOrder)) {
                            $started++;
                        }
                        continue;
                    }

                    try {
                        $action = DB::transaction(function () use (
                            $deliveryOrder,
                            $sourceStatus,
                            $acceptedAt,
                            $estimatedMinutes,
                            $estimatedReadyAt,
                            $readyAt,
                            $isPending,
                            $isCancelled,
                            $isDispatchable,
                        ): string {
                            $locked = DeliveryOrder::query()->lockForUpdate()->findOrFail($deliveryOrder->id);
                            $locked->forceFill([
                                'merchant_status' => $sourceStatus,
                                'merchant_accepted_at' => $acceptedAt,
                                'estimated_preparation_minutes' => $estimatedMinutes,
                                'estimated_ready_at' => $estimatedReadyAt,
                                'merchant_ready_at' => $readyAt,
                            ])->save();

                            if ($locked->driver_id !== null || $this->isAssignedOrInProgress($locked)) {
                                return 'preserved';
                            }

                            if ($isPending && $this->isSearching($locked)) {
                                $locked->assignmentAttempts()->where('status', 'open')->update(['status' => 'cancelled']);
                                $locked->forceFill([
                                    'status' => DeliveryOrderStatus::WaitingMerchantReady->value,
                                    'dispatch_wave' => 0,
                                    'search_radius_km' => null,
                                    'dispatch_phase' => 'radius',
                                    'stopped_at' => null,
                                    'stop_reason' => null,
                                ])->save();

                                return 'pause';
                            }

                            if ($isCancelled && ! $this->isTerminal($locked)) {
                                return 'cancel';
                            }

                            if ($isDispatchable && $this->needsFreshSearch($locked)) {
                                return 'start';
                            }

                            return 'metadata';
                        });

                        $fresh = $deliveryOrder->fresh();
                        if (! $fresh instanceof DeliveryOrder) {
                            continue;
                        }

                        match ($action) {
                            'start' => $deliveries->startDispatch($fresh, 'Merchant delivery state backfilled; driver search started.'),
                            'cancel' => $deliveries->cancel($fresh, 'Merchant source order was already cancelled during readiness backfill.'),
                            default => null,
                        };

                        $updated++;
                        $started += $action === 'start' ? 1 : 0;
                        $paused += $action === 'pause' ? 1 : 0;
                        $cancelled += $action === 'cancel' ? 1 : 0;
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->error("Delivery #{$deliveryOrder->id}: {$exception->getMessage()}");
                    }
                }
            });

        $prefix = $dryRun ? 'Dry run' : 'Completed';
        $this->info("{$prefix}: {$updated} updated, {$started} searches started, {$paused} pending searches paused, {$cancelled} deliveries cancelled, {$missingSources} missing sources.");

        return self::SUCCESS;
    }

    private function isSearching(DeliveryOrder $order): bool
    {
        return in_array($order->status, [
            DeliveryOrderStatus::SearchingForDriver->value,
            DeliveryOrderStatus::Dispatching->value,
            DeliveryOrderStatus::Offered->value,
        ], true);
    }

    private function needsFreshSearch(DeliveryOrder $order): bool
    {
        return in_array($order->status, [
            DeliveryOrderStatus::New->value,
            DeliveryOrderStatus::WaitingMerchantReady->value,
            DeliveryOrderStatus::Stopped->value,
            DeliveryOrderStatus::Rejected->value,
        ], true);
    }

    private function isAssignedOrInProgress(DeliveryOrder $order): bool
    {
        return in_array($order->status, [
            DeliveryOrderStatus::Accepted->value,
            DeliveryOrderStatus::InProgress->value,
            DeliveryOrderStatus::PickedUp->value,
            DeliveryOrderStatus::Delivered->value,
            DeliveryOrderStatus::Completed->value,
        ], true);
    }

    private function isTerminal(DeliveryOrder $order): bool
    {
        return in_array($order->status, [
            DeliveryOrderStatus::Cancelled->value,
            DeliveryOrderStatus::Completed->value,
            DeliveryOrderStatus::Delivered->value,
        ], true);
    }
}
