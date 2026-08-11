<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\SosAlert;
use App\Services\LegacySupportCaseSyncService;

final class SosAlertObserver
{
    public function created(SosAlert $alert): void
    {
        app(LegacySupportCaseSyncService::class)->syncSosAlert($alert);
    }

    public function updated(SosAlert $alert): void
    {
        app(LegacySupportCaseSyncService::class)->syncSosAlert($alert);
    }
}
