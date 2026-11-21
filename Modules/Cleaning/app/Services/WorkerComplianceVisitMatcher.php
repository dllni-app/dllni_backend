<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WorkerComplianceVisitMatcher
{
    public function __construct(
        private readonly WorkerComplianceVisitEvaluator $evaluator,
        private readonly WorkerComplianceOfficialBookingExclusion $officialBookingExclusion,
    ) {}

    public function evaluateWorker(int $workerId): int
    {
        if ($workerId <= 0
            || ! (bool) config('cleaning.worker_compliance.enabled', false)
            || ! (bool) config('cleaning.worker_compliance.matching_enabled', false)) {
            return 0;
        }

        $state = DB::table('worker_compliance_states')->where('worker_id', $workerId)->first();
        if (! $this->isEligibleState($state)) {
            return 0;
        }

        $eligibilities = DB::table('worker_compliance_site_eligibilities')
            ->where('worker_id', $workerId)
            ->whereNotNull('served_at')
            ->where('eligible_until', '>', now())
            ->orderByDesc('served_at')
            ->limit(500)
            ->get();
        if ($eligibilities->isEmpty() || $eligibilities->count() >= 500) {
            return 0;
        }

        $sampleLookbackSeconds = max(
            1200,
            (int) config('cleaning.worker_compliance.minimum_dwell_seconds', 600)
                + 2 * (int) config('cleaning.worker_compliance.maximum_sample_gap_seconds', 240),
        );
        $reorderCutoff = now()->subSeconds(max(
            0,
            (int) config('cleaning.worker_compliance.matching_reorder_buffer_seconds', 120),
        ));
        $storedSamples = DB::table('worker_compliance_samples')
            ->where('worker_id', $workerId)
            ->where('epoch', $state->epoch)
            ->where('captured_at', '>=', now()->subSeconds($sampleLookbackSeconds))
            ->where('captured_at', '<=', $reorderCutoff)
            ->orderBy('captured_at')
            ->limit(500)
            ->get();

        if ($storedSamples->isEmpty() || $storedSamples->count() >= 500) {
            return 0;
        }

        try {
            $samples = $storedSamples->map(static fn (object $sample): array => [
                'captured_at' => $sample->captured_at,
                'latitude' => (float) Crypt::decryptString($sample->latitude_encrypted),
                'longitude' => (float) Crypt::decryptString($sample->longitude_encrypted),
                'accuracy_m' => (float) $sample->accuracy_m,
            ])->all();
            $sites = $eligibilities->map(static fn (object $eligibility): array => [
                'id' => (int) $eligibility->id,
                'site_key' => (string) $eligibility->site_key,
                'booking_id' => (int) $eligibility->cleaning_booking_id,
                'session_id' => $eligibility->cleaning_booking_session_id !== null
                    ? (int) $eligibility->cleaning_booking_session_id
                    : null,
                'served_at' => CarbonImmutable::parse($eligibility->served_at),
                'latitude' => (float) Crypt::decryptString($eligibility->latitude_encrypted),
                'longitude' => (float) Crypt::decryptString($eligibility->longitude_encrypted),
            ])->unique('site_key')->values()->all();
        } catch (Throwable) {
            return 0;
        }

        $caseCount = 0;
        foreach ($sites as $site) {
            $siteSamples = array_values(array_filter(
                $samples,
                static fn (array $sample): bool => CarbonImmutable::parse($sample['captured_at'])
                    ->greaterThanOrEqualTo($site['served_at']),
            ));
            $run = $this->evaluator->qualifyingRun($siteSamples, $site['latitude'], $site['longitude']);
            if ($run === null || $this->overlapsAnotherSite($samples, $sites, $site['site_key'], $run)) {
                continue;
            }

            $officialExplanation = $this->officialBookingExclusion->explainsVisit(
                $workerId,
                $site['latitude'],
                $site['longitude'],
                $run,
            );
            if ($officialExplanation !== false) {
                continue;
            }

            if ($this->recordReviewCase($workerId, (string) $state->epoch, $site, $run)) {
                $caseCount++;
            }
        }

        return $caseCount;
    }

    /** @param array<int, array{captured_at: mixed, latitude: float, longitude: float, accuracy_m: float}> $samples */
    private function overlapsAnotherSite(array $samples, array $sites, string $candidateSiteKey, array $run): bool
    {
        foreach ($samples as $sample) {
            $capturedAt = CarbonImmutable::parse($sample['captured_at']);
            if ($capturedAt->lessThan($run['started_at']) || $capturedAt->greaterThan($run['ended_at'])) {
                continue;
            }

            $matches = 0;
            foreach ($sites as $site) {
                if ($site['site_key'] === $candidateSiteKey) {
                    continue;
                }

                if ($sample['accuracy_m'] <= (float) config('cleaning.worker_compliance.maximum_accuracy_m', 50)
                    && WorkerComplianceVisitEvaluator::distanceMeters(
                        $sample['latitude'],
                        $sample['longitude'],
                        $site['latitude'],
                        $site['longitude'],
                    ) + $sample['accuracy_m'] <= (float) config('cleaning.worker_compliance.proximity_radius_m', 100)) {
                    $matches++;
                    if ($matches > 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array{site_key: string, booking_id: int, session_id: ?int, served_at: CarbonImmutable, latitude: float, longitude: float} $site */
    private function recordReviewCase(int $workerId, string $epoch, array $site, array $run): bool
    {
        return DB::transaction(function () use ($workerId, $epoch, $site, $run): bool {
            $state = DB::table('worker_compliance_states')
                ->where('worker_id', $workerId)
                ->lockForUpdate()
                ->first();
            if (! $this->isEligibleState($state) || ! hash_equals((string) $state->epoch, $epoch)) {
                return false;
            }

            DB::table('worker_compliance_site_eligibilities')
                ->where('worker_id', $workerId)
                ->where('site_key', $site['site_key'])
                ->whereNotNull('served_at')
                ->where('served_at', '<=', $run['started_at'])
                ->where('eligible_until', '>', now())
                ->lockForUpdate()
                ->get(['id']);

            if ($this->officialBookingExclusion->explainsVisit(
                $workerId,
                $site['latitude'],
                $site['longitude'],
                $run,
                true,
            ) !== false) {
                return false;
            }

            $cooldownStart = now()->subHours(max(1, (int) config('cleaning.worker_compliance.review_cooldown_hours', 24)));
            $existing = DB::table('worker_compliance_review_cases')
                ->where('worker_id', $workerId)
                ->where('site_key', $site['site_key'])
                ->where('created_at', '>=', $cooldownStart)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (in_array($existing->status, ['new', 'under_review'], true)) {
                    DB::table('worker_compliance_review_cases')->where('id', $existing->id)->update([
                        'observation_started_at' => CarbonImmutable::parse($existing->observation_started_at)
                            ->min($run['started_at']),
                        'observation_ended_at' => CarbonImmutable::parse($existing->observation_ended_at)
                            ->max($run['ended_at']),
                        'sample_count' => max((int) $existing->sample_count, $run['sample_count']),
                        'updated_at' => now(),
                    ]);
                }

                return false;
            }

            $previousCase = DB::table('worker_compliance_review_cases')
                ->where('worker_id', $workerId)
                ->where('site_key', $site['site_key'])
                ->orderByDesc('observation_ended_at')
                ->lockForUpdate()
                ->first();
            if ($previousCase !== null && ! $this->hasCredibleExitAndReentry(
                $workerId,
                $site,
                CarbonImmutable::parse($previousCase->observation_ended_at),
                $run['started_at'],
            )) {
                return false;
            }

            $distanceBand = $run['min_distance_m'] <= 25 ? 'within_25m'
                : ($run['min_distance_m'] <= 50 ? 'within_50m' : 'within_100m');
            $accuracyBand = $run['max_accuracy_m'] <= 10 ? 'within_10m'
                : ($run['max_accuracy_m'] <= 25 ? 'within_25m' : 'within_50m');
            $now = now();
            $caseReference = (string) \Illuminate\Support\Str::uuid();
            $caseId = DB::table('worker_compliance_review_cases')->insertGetId([
                'worker_id' => $workerId,
                'site_key' => $site['site_key'],
                'cleaning_booking_id' => $site['booking_id'],
                'cleaning_booking_session_id' => $site['session_id'],
                'observation_started_at' => $run['started_at'],
                'observation_ended_at' => $run['ended_at'],
                'sample_count' => $run['sample_count'],
                'distance_band' => $distanceBand,
                'accuracy_band' => $accuracyBand,
                'rule_version' => (string) config('cleaning.worker_compliance.rule_version', 'pilot-v1'),
                'reason_code' => 'potential_visit_needs_review',
                'status' => 'new',
                'public_reference' => $caseReference,
                'dedupe_key' => hash('sha256', implode(':', [
                    $workerId,
                    $site['site_key'],
                    $run['started_at']->getTimestamp(),
                ])),
                'expires_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('worker_compliance_review_events')->insert([
                'case_id' => $caseId,
                'case_reference' => $caseReference,
                'event_type' => 'created',
                'to_status' => 'new',
                'reason' => 'Potential proximity pattern requires authorized human review.',
                'expires_at' => $now->copy()->addDays(max(1, (int) config('cleaning.worker_compliance.review_audit_retention_days', 180))),
                'created_at' => $now,
            ]);

            // This is an investigative lead, not evidence of misconduct.
            // Notify only authorized admin reviewers, after durable commit.
            // Do not include a customer identity, site, or raw location.
            if ((bool) config('cleaning.worker_compliance.notifications_enabled', false)) {
                DB::afterCommit(static function () use ($caseReference): void {
                    try {
                        app(WorkerComplianceReviewerNotificationService::class)
                            ->notifyForNewCase($caseReference);
                    } catch (\Throwable $exception) {
                        \Illuminate\Support\Facades\Log::warning(
                            'Worker compliance reviewer notification failed.',
                            ['exception_type' => $exception::class],
                        );
                    }
                });
            }

            return true;
        }, 3);
    }

    private function hasCredibleExitAndReentry(
        int $workerId,
        array $site,
        CarbonImmutable $previousVisitEnd,
        CarbonImmutable $currentVisitStart,
    ): bool {
        if (! $currentVisitStart->greaterThan($previousVisitEnd)) {
            return false;
        }

        $samples = DB::table('worker_compliance_samples')
            ->where('worker_id', $workerId)
            ->where('captured_at', '>', $previousVisitEnd)
            ->where('captured_at', '<', $currentVisitStart)
            ->orderBy('captured_at')
            ->limit(500)
            ->get();
        if ($samples->count() >= 500) {
            return false;
        }

        try {
            $outside = $samples->map(static fn (object $sample): ?CarbonImmutable =>
                (float) $sample->accuracy_m <= (float) config('cleaning.worker_compliance.maximum_accuracy_m', 50)
                    && WorkerComplianceVisitEvaluator::distanceMeters(
                        (float) Crypt::decryptString($sample->latitude_encrypted),
                        (float) Crypt::decryptString($sample->longitude_encrypted),
                        $site['latitude'],
                        $site['longitude'],
                    ) - (float) $sample->accuracy_m > (float) config('cleaning.worker_compliance.proximity_radius_m', 100)
                    ? CarbonImmutable::parse($sample->captured_at)
                    : null,
            )->filter()->values();
        } catch (Throwable) {
            return false;
        }

        $maximumGap = max(1, 2 * (int) config('cleaning.worker_compliance.maximum_sample_gap_seconds', 240));
        for ($index = 1; $index < $outside->count(); $index++) {
            $gap = $outside[$index - 1]->diffInSeconds($outside[$index]);
            if ($gap > 0 && $gap <= $maximumGap) {
                return true;
            }
        }

        return false;
    }

    private function isEligibleState(?object $state): bool
    {
        return $state !== null
            && (bool) $state->is_enrolled
            && $state->epoch !== null
            && $state->revoked_at === null
            && $state->lease_expires_at !== null
            && CarbonImmutable::parse($state->lease_expires_at)->isFuture()
            && in_array($state->platform, config('cleaning.worker_compliance.enabled_platforms', []), true)
            && version_compare(
                (string) $state->build,
                (string) config('cleaning.worker_compliance.minimum_supported_build', '0'),
                '>=',
            );
    }
}
