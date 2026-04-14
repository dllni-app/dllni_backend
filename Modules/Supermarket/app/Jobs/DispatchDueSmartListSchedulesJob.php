<?php

declare(strict_types=1);

namespace Modules\Supermarket\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Supermarket\Models\SmSmartListSchedule;

final class DispatchDueSmartListSchedulesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        SmSmartListSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->with('smartList')
            ->chunkById(100, function ($schedules): void {
                foreach ($schedules as $schedule) {
                    if (! $schedule->smartList?->is_active) {
                        continue;
                    }

                    ProcessSmartListScheduleJob::dispatch($schedule->id);
                }
            });
    }
}
