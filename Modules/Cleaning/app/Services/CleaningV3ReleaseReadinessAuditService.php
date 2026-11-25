<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Read-only automated preflight. Passing the structural gate is never a
 * substitute for human tariff/funding approval, MySQL concurrency QA,
 * application compatibility testing or signed publication evidence.
 */
final class CleaningV3ReleaseReadinessAuditService
{
    /** @return array<string,mixed> */
    public function audit(): array
    {
        $checks = [];

        $driver = DB::connection()->getDriverName();
        $checks[] = $this->check(
            'mysql_qa',
            $driver === 'mysql',
            'Requires connected MySQL for real row-lock and financial race verification.',
            ['connectionDriver' => $driver],
        );

        foreach ([
            'cleaning_v3_catalog_versions',
            'cleaning_v3_catalog_items',
            'cleaning_v3_catalog_tariff_rules',
            'cleaning_v3_catalog_publication',
            'cleaning_v3_cash_movements',
            'cleaning_v3_supervisor_attendance_proofs',
            'cleaning_booking_sessions',
            'cleaning_visit_funding_reservations',
            'cleaning_work_intervals',
            'cleaning_work_pause_requests',
            'cleaning_v3_visit_dispatches',
            'cleaning_v3_dispatch_notice_intents',
        ] as $table) {
            $checks[] = $this->check(
                'table_'.$table,
                Schema::hasTable($table),
                'The required additive V3/funding table must physically exist.',
            );
        }

        $approvedDigest = (string) \Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::digest();
        $digestValid = preg_match('/^[a-f0-9]{64}$/D', $approvedDigest) === 1;
        $checks[] = $this->check(
            'approved_catalog_digest',
            $digestValid && (bool) config('cleaning_v3_catalog.read_enabled', false),
            'Requires exact reviewed published digest and enabled read capability.',
        );

        $catalog = null;
        if ($digestValid && Schema::hasTable('cleaning_v3_catalog_versions')) {
            $catalog = CleaningV3CatalogVersion::query()
                ->where('status', CleaningV3CatalogVersion::PUBLISHED)
                ->where('published_digest', $approvedDigest)
                ->whereNotNull('published_at')
                ->first();
        }

        $checks[] = $this->check(
            'published_catalog_integrity',
            $catalog !== null,
            'A real immutable catalog matching the approved revision must be present.',
        );

        if ($catalog !== null) {
            $issues = config('cleaning_v3_catalog.admin_publication_enabled', false)
                ? app(CleaningV3AdminCatalogPublication::class)->issues($catalog)
                : app(CleaningV3CatalogDraftValidator::class)->review($catalog);
            $checks[] = $this->check('admin_catalog_publication', $issues === []
                && config('cleaning_v3_catalog.admin_publication_enabled', false),
                'Administrative publication and supported tariff coverage are independent of actual cash collection.',
                ['issueCodes' => array_values($issues)]);
            $checks[] = $this->check(
                'approved_catalog_coverage_and_funding',
                false,
                'Catalog structure can pass independently; actual funding and complete booking admission evidence are still required.',
                ['issueCodes' => array_values($issues)],
            );
        } else {
            $checks[] = $this->check(
                'approved_catalog_coverage_and_funding',
                false,
                'No reviewed published catalog is available for a full proof check.',
            );
        }

        // This is an explicit technical blocker, NOT a feature flag.
        // It cannot be made to pass by an env toggle or editing the report.
        $checks[] = $this->check(
            'signed_v3_financial_admission',
            false,
            'Cash quote and manual treasury reconciliation are implemented; booking and worker admission, verified supervised attendance, and actual funding/cash workflow integration remain required.'
        );
        $checks[] = $this->check(
            'mysql_concurrency_and_mobile_e2e_evidence',
            false,
            'Requires separately recorded MySQL financial races and joint device tests after implementation.',
        );

        $blockers = array_values(array_filter(
            $checks, static fn (array $check): bool => ! $check['pass'],
        ));

        return [
            'version' => 3,
            'readOnly' => true,
            'readyForCommercialLaunch' => $blockers === [],
            'checks' => $checks,
            'blockerCodes' => array_column($blockers, 'code'),
            'counts' => [
                'total' => count($checks),
                'passed' => count($checks) - count($blockers),
                'blocked' => count($blockers),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    private function check(
        string $code,
        bool $pass,
        string $explanation,
        array $details = [],
    ): array {
        return [
            'code' => $code,
            'pass' => $pass,
            'explanation' => $explanation,
            'details' => $details,
        ];
    }
}
