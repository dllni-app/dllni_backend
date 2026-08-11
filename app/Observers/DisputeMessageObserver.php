<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DisputeMessage;
use App\Services\LegacySupportCaseSyncService;

final class DisputeMessageObserver
{
    public function created(DisputeMessage $message): void
    {
        app(LegacySupportCaseSyncService::class)->syncDisputeMessage($message->loadMissing('dispute'));
    }
}
