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

        $approvedDigest = (string) config('cleaning_v3_catalog.approved_publication_digest', '');
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
            $issues = app(CleaningV3CatalogDraftValidator::class)->review($catalog);
            $checks[] = $this->check(
                'approved_catalog_coverage_and_funding',
                $issues === [],
                'All missing catalog coverage, pricing, refund and funding proofs must be approved.',
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
            'Public V3 quote and booking controllers remain fail-closed until implementation and independently verified cleared funding.',
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
