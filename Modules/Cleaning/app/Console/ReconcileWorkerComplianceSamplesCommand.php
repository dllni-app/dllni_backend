<?php

declare(strict_types=1);

namespace Modules\Cleaning\Console;

use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Jobs\EvaluateWorkerComplianceSamples;

#[AsCommand(name: 'cleaning:reconcile-worker-compliance-samples')]
final class ReconcileWorkerComplianceSamplesCommand extends Command
{
    protected $signature = 'cleaning:reconcile-worker-compliance-samples';

    protected $description = 'Queue delayed compliance matching for currently enrolled worker sessions';

    public function handle(): int
    {
        if (! (bool) config('cleaning.worker_compliance.enabled', false)
            || ! (bool) config('cleaning.worker_compliance.matching_enabled', false)) {
            return self::SUCCESS;
        }

        $delaySeconds = max(0, (int) config('cleaning.worker_compliance.matching_reorder_buffer_seconds', 120));
        DB::table('worker_compliance_states')
            ->where('is_enrolled', true)
            ->whereNotNull('epoch')
            ->whereNull('revoked_at')
            ->where('lease_expires_at', '>', now())
            ->orderBy('worker_id')
            ->chunkById(500, static function ($states) use ($delaySeconds): void {
                foreach ($states as $state) {
                    EvaluateWorkerComplianceSamples::dispatch((int) $state->worker_id)
                        ->delay(now()->addSeconds($delaySeconds));
                }
            }, 'worker_id');

        return self::SUCCESS;
    }
}
