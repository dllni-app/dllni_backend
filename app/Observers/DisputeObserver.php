<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\NotifyWorkerDisputeOpenedJob;
use App\Models\Dispute;
use App\Services\LegacySupportCaseSyncService;
use Modules\Delivery\Services\DeliveryDisputeService;

final class DisputeObserver
{
    public function created(Dispute $dispute): void
    {
        NotifyWorkerDisputeOpenedJob::dispatch($dispute->id);
        app(LegacySupportCaseSyncService::class)->syncDispute($dispute);

        if ($dispute->booking_type === 'delivery_order') {
            app(DeliveryDisputeService::class)->handleCreated($dispute);
        }
    }

    public function updated(Dispute $dispute): void
    {
        app(LegacySupportCaseSyncService::class)->syncDispute($dispute);

        if ($dispute->booking_type !== 'delivery_order' || ! $dispute->wasChanged('status')) {
            return;
        }

        app(DeliveryDisputeService::class)->handleStatusChanged($dispute);
    }
}
