<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverTrustLog;
use Modules\Delivery\Models\DeliveryOrder;

final class DriverTrustService
{
    public function __construct(
        private readonly DeliveryNotificationService $notifications,
        private readonly FinancialLedgerService $ledgerService,
        private readonly FinancialSuspensionService $suspensionService,
    ) {}

    public function incrementOpenDisputes(DeliveryDriver $driver): void
    {
        DeliveryDriver::query()
            ->whereKey($driver->id)
            ->increment('open_disputes_count');
    }

    public function decrementOpenDisputes(DeliveryDriver $driver): void
    {
        $driver->refresh();

        DeliveryDriver::query()
            ->whereKey($driver->id)
            ->update([
                'open_disputes_count' => max(0, (int) $driver->open_disputes_count - 1),
            ]);
    }

    public function applyDisputePenalty(DeliveryDriver $driver, Dispute $dispute, ?int $delta = null): void
    {
        $delta ??= (int) config('delivery.trust.dispute_penalty', 10);

        $this->adjustScore(
            driver: $driver,
            delta: -abs($delta),
            reason: 'dispute_penalty',
            relatedDisputeId: $dispute->id,
        );
    }

    public function recoverScore(DeliveryDriver $driver, int $points): void
    {
        $maxScore = (int) config('delivery.trust.max_score', 100);

        if ((int) $driver->trust_score >= $maxScore || (int) $driver->open_disputes_count > 0) {
            return;
        }

        $this->adjustScore(
            driver: $driver,
            delta: max(1, $points),
            reason: 'scheduled_recovery',
        );
    }

    public function handleDisputeOpened(DeliveryOrder $order, Dispute $dispute): void
    {
        $driver = $order->driver;

        if (! $driver instanceof DeliveryDriver) {
            return;
        }

        $this->incrementOpenDisputes($driver);
    }

    public function handleDisputeStatusChanged(DeliveryOrder $order, Dispute $dispute): void
    {
        $driver = $order->driver;

        if (! $driver instanceof DeliveryDriver) {
            return;
        }

        $previous = DisputeStatus::tryFrom((string) $dispute->getOriginal('status'));
        $current = $dispute->status instanceof DisputeStatus
            ? $dispute->status
            : DisputeStatus::tryFrom((string) $dispute->status);

        if ($current === null) {
            return;
        }

        if ($previous?->isTerminal() || ! $current->isTerminal()) {
            return;
        }

        $this->decrementOpenDisputes($driver);

        $resolution = $dispute->resolution instanceof DisputeResolution
            ? $dispute->resolution
            : DisputeResolution::tryFrom((string) ($dispute->resolution ?? ''));

        if ($resolution === DisputeResolution::WorkerPenalty) {
            $this->applyDisputePenalty($driver->fresh(), $dispute);
            $this->recordDisputeFinancialPenalty($order, $dispute);
        }
    }

    private function recordDisputeFinancialPenalty(DeliveryOrder $order, Dispute $dispute): void
    {
        $amount = (float) config('delivery.financial.dispute_penalty_amount', 0);

        if ($amount <= 0) {
            return;
        }

        $order->loadMissing('company');
        $company = $order->company;

        if ($company === null) {
            return;
        }

        $transaction = $this->ledgerService->recordDisputePenaltyDebit($company, $dispute, $amount);

        if ($transaction !== null) {
            $account = $this->ledgerService->accountForCompany($company);
            $this->suspensionService->evaluateCompanyAccount($account->fresh(), $company);
        }
    }

    private function adjustScore(
        DeliveryDriver $driver,
        int $delta,
        string $reason,
        ?int $relatedDisputeId = null,
    ): void {
        DB::transaction(function () use ($driver, $delta, $reason, $relatedDisputeId): void {
            $lockedDriver = DeliveryDriver::query()->lockForUpdate()->find($driver->id);

            if (! $lockedDriver instanceof DeliveryDriver) {
                return;
            }

            $maxScore = (int) config('delivery.trust.max_score', 100);
            $scoreAfter = max(0, min($maxScore, (int) $lockedDriver->trust_score + $delta));

            if ($scoreAfter === (int) $lockedDriver->trust_score) {
                return;
            }

            DeliveryDriverTrustLog::query()->create([
                'driver_id' => $lockedDriver->id,
                'reason' => $reason,
                'score_delta' => $delta,
                'score_after' => $scoreAfter,
                'related_dispute_id' => $relatedDisputeId,
            ]);

            $lockedDriver->forceFill(['trust_score' => $scoreAfter])->save();

            $this->notifications->notifyTrustScoreChanged($lockedDriver->fresh(), $delta, $scoreAfter, $reason);
        });
    }
}
