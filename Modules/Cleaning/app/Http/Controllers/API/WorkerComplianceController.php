<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Jobs\EvaluateWorkerComplianceSamples;

final class WorkerComplianceController
{
    public function config(Request $request): JsonResponse
    {
        $worker = $this->worker($request);
        $state = $worker === null ? null : DB::table('worker_compliance_states')->where('worker_id', $worker->id)->first();

        return response()->json([
            'featureEnabled' => $this->featureEnabledFor($request->query('platform'), $request->query('build')),
            'enrolled' => (bool) ($state->is_enrolled ?? false),
            'noticeVersion' => config('cleaning.worker_compliance.notice_version'),
            'privacyPolicyUrl' => config('cleaning.worker_compliance.privacy_policy_url'),
            'minimumSupportedBuild' => config('cleaning.worker_compliance.minimum_supported_build'),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    public function createSession(Request $request): JsonResponse
    {
        $worker = $this->authorizedWorker($request);
        $validated = $request->validate([
            'installationId' => ['required', 'string', 'min:16', 'max:128'],
            'platform' => ['required', 'in:android,ios'],
            'build' => ['required', 'string', 'max:64'],
            'noticeVersion' => ['required', 'string', 'max:64'],
        ]);

        if ($validated['noticeVersion'] !== config('cleaning.worker_compliance.notice_version')) {
            return response()->json(['message' => 'The compliance notice must be refreshed.'], 409);
        }

        if (! $this->featureEnabledFor($validated['platform'], $validated['build'])) {
            return response()->json(['message' => 'Compliance collection is unavailable for this platform or build.'], 403);
        }

        $installationHash = hash('sha256', $validated['installationId']);
        $accessTokenHash = $this->accessTokenHash($request);

        $session = DB::transaction(function () use ($worker, $installationHash, $accessTokenHash, $validated): ?object {
            $state = DB::table('worker_compliance_states')->where('worker_id', $worker->id)->lockForUpdate()->first();
            if ($state === null || ! (bool) $state->is_enrolled) {
                return null;
            }

            if (
                $state->epoch !== null
                && hash_equals((string) $state->installation_hash, $installationHash)
                && hash_equals((string) $state->access_token_hash, $accessTokenHash)
                && $state->notice_version === $validated['noticeVersion']
                && $state->platform === $validated['platform']
                && $state->build === $validated['build']
                && $state->revoked_at === null
                && $state->lease_expires_at !== null
                && CarbonImmutable::parse($state->lease_expires_at)->isFuture()
            ) {
                return $state;
            }

            $epoch = (string) Str::uuid();
            $leaseExpiresAt = now()->addMinutes((int) config('cleaning.worker_compliance.session_lease_minutes', 60));
            DB::table('worker_compliance_states')->where('worker_id', $worker->id)->update([
                'installation_hash' => $installationHash,
                'access_token_hash' => $accessTokenHash,
                'platform' => $validated['platform'],
                'build' => $validated['build'],
                'epoch' => $epoch,
                'notice_version' => $validated['noticeVersion'],
                'lease_expires_at' => $leaseExpiresAt,
                'revoked_at' => null,
                'last_contact_at' => now(),
                'updated_at' => now(),
            ]);

            return (object) ['epoch' => $epoch, 'lease_expires_at' => $leaseExpiresAt];
        });

        if ($session === null) {
            return response()->json(['message' => 'Compliance collection is unavailable for this worker.'], 403);
        }

        return response()->json([
            'epoch' => $session->epoch,
            'leaseExpiresAt' => CarbonImmutable::parse($session->lease_expires_at)->toIso8601String(),
        ]);
    }

    public function storeSamples(Request $request): JsonResponse
    {
        $worker = $this->authorizedWorker($request);
        $validated = $request->validate([
            'epoch' => ['required', 'uuid'],
            'permissionState' => ['required', 'in:denied,while_in_use,always,restricted,unknown'],
            'locationServicesEnabled' => ['required', 'boolean'],
            'samples' => ['present', 'array', 'max:'.(int) config('cleaning.worker_compliance.maximum_batch_size', 20)],
            'samples.*.id' => ['required', 'uuid'],
            'samples.*.capturedAt' => ['required', 'date'],
            'samples.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'samples.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'samples.*.accuracyM' => ['required', 'numeric', 'between:0,20000'],
        ]);

        $now = CarbonImmutable::now();
        $result = DB::transaction(function () use ($request, $worker, $validated, $now): ?array {
            $state = DB::table('worker_compliance_states')
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();
            if ($state === null || ! (bool) $state->is_enrolled) {
                return ['failureStatus' => 403];
            }

            if ($state->epoch !== null && ! $this->featureEnabledFor($state->platform, $state->build)) {
                return ['failureStatus' => 403];
            }

            if (! $this->hasCurrentSession($request, $state, $validated['epoch'])) {
                return ['failureStatus' => 409];
            }

            $accepted = [];
            $duplicates = [];
            $rejected = [];
            $latestUsableFix = $state->last_usable_fix_at === null
                ? null
                : CarbonImmutable::parse($state->last_usable_fix_at);
            foreach ($validated['samples'] as $sample) {
                $capturedAt = CarbonImmutable::parse($sample['capturedAt']);
                $clientId = $sample['id'];

                if ($capturedAt->greaterThan($now->addMinutes((int) config('cleaning.worker_compliance.maximum_future_skew_minutes', 5)))) {
                    $rejected[] = ['id' => $clientId, 'reason' => 'future_timestamp'];

                    continue;
                }

                if ($capturedAt->lessThan($now->subMinutes((int) config('cleaning.worker_compliance.maximum_sample_age_minutes', 15)))) {
                    $rejected[] = ['id' => $clientId, 'reason' => 'late_sample'];
                    DB::table('worker_compliance_states')->where('worker_id', $worker->id)->increment('late_sample_count');

                    continue;
                }

                $maxAccuracy = max(1.0, (float) config('cleaning.worker_compliance.maximum_accuracy_m', 50));
                $quality = (float) $sample['accuracyM'] <= $maxAccuracy ? 'usable' : 'poor_accuracy';
                $inserted = DB::table('worker_compliance_samples')->insertOrIgnore([
                    'worker_id' => $worker->id,
                    'epoch' => $validated['epoch'],
                    'client_id' => $clientId,
                    'captured_at' => $capturedAt,
                    'received_at' => $now,
                    'latitude_encrypted' => Crypt::encryptString((string) $sample['latitude']),
                    'longitude_encrypted' => Crypt::encryptString((string) $sample['longitude']),
                    'accuracy_m' => $sample['accuracyM'],
                    'quality' => $quality,
                    'expires_at' => $now->addDays((int) config('cleaning.worker_compliance.sample_retention_days', 7)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($inserted === 0) {
                    $duplicates[] = $clientId;

                    continue;
                }

                $accepted[] = $clientId;

                if ($quality === 'usable' && ($latestUsableFix === null || $capturedAt->greaterThan($latestUsableFix))) {
                    $latestUsableFix = $capturedAt;
                }
            }

            $leaseExpiresAt = $now->addMinutes((int) config('cleaning.worker_compliance.session_lease_minutes', 60));
            DB::table('worker_compliance_states')
                ->where('worker_id', $worker->id)
                ->where('epoch', $validated['epoch'])
                ->where('access_token_hash', $this->accessTokenHash($request))
                ->update([
                    'last_contact_at' => $now,
                    'last_usable_fix_at' => $latestUsableFix,
                    'last_reported_permission_state' => $validated['permissionState'],
                    'last_reported_location_services_enabled' => (bool) $validated['locationServicesEnabled'],
                    'lease_expires_at' => $leaseExpiresAt,
                    'updated_at' => $now,
                ]);

            return [
                'acceptedIds' => $accepted,
                'duplicateIds' => $duplicates,
                'rejected' => $rejected,
                'leaseExpiresAt' => $leaseExpiresAt,
            ];
        });

        if (isset($result['failureStatus'])) {
            $status = $result['failureStatus'];
            $message = $status === 403
                ? 'Compliance collection is unavailable for this worker, platform, or build.'
                : 'Compliance session is no longer current.';

            return response()->json(['message' => $message], $status);
        }

        if ($result['acceptedIds'] !== [] && config('cleaning.worker_compliance.matching_enabled', false)) {
            EvaluateWorkerComplianceSamples::dispatch((int) $worker->id)
                ->delay(now()->addSeconds((int) config('cleaning.worker_compliance.matching_reorder_buffer_seconds', 120)));
        }

        return response()->json([
            'acceptedIds' => $result['acceptedIds'],
            'duplicateIds' => $result['duplicateIds'],
            'rejected' => $result['rejected'],
            'serverTime' => $now->toIso8601String(),
            'leaseExpiresAt' => CarbonImmutable::parse($result['leaseExpiresAt'])->toIso8601String(),
        ]);
    }

    public function revokeSession(Request $request): JsonResponse
    {
        $worker = $this->worker($request);
        if ($worker !== null) {
            DB::table('worker_compliance_states')
                ->where('worker_id', $worker->id)
                ->where('access_token_hash', $this->accessTokenHash($request))
                ->update([
                    'epoch' => null,
                    'access_token_hash' => null,
                    'lease_expires_at' => null,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([], 204);
    }

    private function authorizedWorker(Request $request): Worker
    {
        abort_unless((bool) config('cleaning.worker_compliance.enabled', false), 403, 'Compliance collection is disabled.');

        $worker = $this->worker($request);
        abort_if($worker === null || ! $worker->is_active || $worker->is_suspended, 403, 'Worker account is unavailable.');

        return $worker;
    }

    private function worker(Request $request): ?Worker
    {
        return $request->user()?->worker;
    }

    private function accessTokenHash(Request $request): string
    {
        $tokenId = $request->user()?->currentAccessToken()?->getKey();

        return hash('sha256', (string) $tokenId);
    }

    private function featureEnabledFor(?string $platform, ?string $build): bool
    {
        if (! (bool) config('cleaning.worker_compliance.enabled', false) || $platform === null || $build === null) {
            return false;
        }

        // Privacy notice must be accessible before any location collection.
        // Disabling or withdrawing the approved URL immediately revokes active
        // sessions through hasCurrentSession(), without deleting their audit.
        $policyUrl = (string) config('cleaning.worker_compliance.privacy_policy_url', '');
        if (filter_var($policyUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($policyUrl, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $minimumBuild = (string) config('cleaning.worker_compliance.minimum_supported_build', '0');

        return in_array($platform, config('cleaning.worker_compliance.enabled_platforms', []), true)
            && version_compare($build, $minimumBuild, '>=');
    }

    private function hasCurrentSession(Request $request, ?object $state, string $epoch): bool
    {
        return $state !== null
            && (bool) $state->is_enrolled
            && $state->epoch !== null
            && hash_equals((string) $state->epoch, $epoch)
            && hash_equals((string) $state->access_token_hash, $this->accessTokenHash($request))
            && $state->notice_version === config('cleaning.worker_compliance.notice_version')
            && $this->featureEnabledFor($state->platform, $state->build)
            && $state->revoked_at === null
            && $state->lease_expires_at !== null
            && CarbonImmutable::parse($state->lease_expires_at)->isFuture();
    }
}
