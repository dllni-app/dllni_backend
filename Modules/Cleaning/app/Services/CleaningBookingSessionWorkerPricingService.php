<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingSessionWorkerPricingService
{
    public function __construct(
        private readonly CleaningPricingCalculator $pricingCalculator,
    ) {}

    /**
     * @return array{serviceShareAmount:float,travelFee:float,adminMarginAmount:float,workerAmount:float,currency:string}
     */
    public function quoteForNextSeat(
        CleaningBookingSession $session,
        Worker $worker,
        int $acceptedBefore,
    ): array {
        $booking = $session->relationLoaded('booking')
            ? $session->booking
            : $session->booking()->firstOrFail();

        $requiredWorkers = max(1, (int) ($session->required_workers ?? 1));
        $slot = min($requiredWorkers, max(1, $acceptedBefore + 1));
        $generalServiceTotal = max(0.0, (float) $session->base_price + (float) $session->addons_total);
        $targetAdminMargin = max(0.0, (float) $session->admin_margin_amount);

        if ($targetAdminMargin <= 0.0 && $generalServiceTotal > 0.0) {
            $targetAdminMargin = (float) $this->pricingCalculator
                ->provisional($generalServiceTotal, 0.0)['adminMargin'];
        }

        $serviceShare = $this->allocateSlotAmount($generalServiceTotal, $requiredWorkers, $slot);
        $adminMarginShare = $this->allocateSlotAmount($targetAdminMargin, $requiredWorkers, $slot);
        $travel = $this->travelForWorker($booking, $serviceShare, $worker);

        return [
            'serviceShareAmount' => round($serviceShare, 2),
            'travelFee' => round($travel, 2),
            'adminMarginAmount' => round($adminMarginShare, 2),
            // Platform margin is customer/platform money and is not deducted
            // a second time from the worker's service + travel entitlement.
            'workerAmount' => round(max(0.0, $serviceShare + $travel), 2),
            'currency' => (string) config('app.currency', 'SYP'),
        ];
    }

    private function travelForWorker(CleaningBooking $booking, float $serviceShare, Worker $worker): float
    {
        $pricing = $this->pricingCalculator->finalizedForWorker(
            $serviceShare,
            0.0,
            $booking->address_latitude !== null ? (float) $booking->address_latitude : null,
            $booking->address_longitude !== null ? (float) $booking->address_longitude : null,
            $worker,
        );

        return (float) ($pricing['travelFee'] ?? 0.0);
    }

    private function allocateSlotAmount(float $total, int $slots, int $slot): float
    {
        if ($total <= 0.0) {
            return 0.0;
        }

        $slots = max(1, $slots);
        $slot = max(1, min($slots, $slot));
        $regularShare = round($total / $slots, 2);

        if ($slot < $slots) {
            return $regularShare;
        }

        return round(max(0.0, $total - ($regularShare * ($slots - 1))), 2);
    }
}
