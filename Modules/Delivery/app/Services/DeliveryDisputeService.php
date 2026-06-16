<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Support\ResolvesDisputeStatus;

final class DeliveryDisputeService
{
    use ResolvesDisputeStatus;

    public function __construct(
        private readonly DeliveryNotificationService $notifications,
        private readonly DriverTrustService $trustService,
    ) {}

    public function handleCreated(Dispute $dispute): void
    {
        $order = $this->resolveOrder($dispute);

        if (! $order instanceof DeliveryOrder) {
            return;
        }

        $this->trustService->handleDisputeOpened($order, $dispute);
        $this->notifications->notifyDisputeOpened($order, $dispute);
    }

    public function handleStatusChanged(Dispute $dispute): void
    {
        $order = $this->resolveOrder($dispute);

        if (! $order instanceof DeliveryOrder) {
            return;
        }

        $previous = $this->resolvePreviousDisputeStatus($dispute);
        $current = $this->resolveDisputeStatus($dispute->status);

        if ($current === null) {
            return;
        }

        if ($previous?->isTerminal() || ! $current->isTerminal()) {
            return;
        }

        $this->trustService->handleDisputeStatusChanged($order, $dispute);

        if ($current === DisputeStatus::Rejected) {
            $this->notifications->notifyDisputeRejected($order, $dispute);

            return;
        }

        $this->notifications->notifyDisputeResolved($order, $dispute);
    }

    private function resolveOrder(Dispute $dispute): ?DeliveryOrder
    {
        if ($dispute->booking_type !== 'delivery_order') {
            return null;
        }

        return DeliveryOrder::query()
            ->with(['driver.user', 'company.owner', 'company.staff.user'])
            ->find($dispute->booking_id);
    }
}
