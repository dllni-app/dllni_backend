<?php

declare(strict_types=1);

namespace Modules\Cleaning\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PurgeWorkerComplianceSamplesCommand extends Command
{
    protected $signature = 'cleaning:purge-compliance-samples';

    protected $description = 'Remove expired worker compliance records in bounded batches';

    public function handle(): int
    {
        foreach ([
            'worker_compliance_samples' => 'expires_at',
            'worker_compliance_site_eligibilities' => 'eligible_until',
            'worker_compliance_review_cases' => 'expires_at',
            'worker_compliance_review_events' => 'expires_at',
        ] as $table => $expiryColumn) {
            $ids = DB::table($table)
                ->where($expiryColumn, '<=', now())
                ->orderBy($expiryColumn)
                ->limit(1000)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table($table)->whereIn('id', $ids)->delete();
            }
        }

        return self::SUCCESS;
    }
}
