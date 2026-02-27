<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Jobs\NotifyWorkerExtensionRequestJob;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningTimeWarningObserver
{
    public function created(CleaningTimeWarning $timeWarning): void
    {
        NotifyWorkerExtensionRequestJob::dispatch($timeWarning->id);
    }
}
