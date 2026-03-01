<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\NotifyWorkerDisputeOpenedJob;
use App\Models\Dispute;

final class DisputeObserver
{
    public function created(Dispute $dispute): void
    {
        NotifyWorkerDisputeOpenedJob::dispatch($dispute->id);
    }
}
