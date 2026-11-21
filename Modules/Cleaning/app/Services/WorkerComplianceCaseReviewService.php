<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\WorkerComplianceReviewCase;

final class WorkerComplianceCaseReviewService
{
    public function claim(WorkerComplianceReviewCase $case, User $actor): void
    {
        Gate::forUser($actor)->authorize('claim', $case);

        DB::transaction(function () use ($case, $actor): void {
            $lockedCase = WorkerComplianceReviewCase::query()->lockForUpdate()->findOrFail($case->getKey());

            if ($lockedCase->status !== 'new' || $lockedCase->claimed_by_user_id !== null) {
                throw ValidationException::withMessages(['status' => 'This case has already been claimed or reviewed. Refresh the queue.']);
            }

            $from = $lockedCase->status;
            $lockedCase->forceFill([
                'status' => 'under_review',
                'claimed_by_user_id' => $actor->getKey(),
            ])->save();

            $this->recordEvent($lockedCase, $actor, 'claimed', $from, 'under_review', null, null, null);
        });
    }

    public function resolve(
        WorkerComplianceReviewCase $case,
        User $actor,
        string $status,
        string $reason,
        ?string $corroboratingReference = null,
    ): void {
        Gate::forUser($actor)->authorize('review', $case);

        if (mb_trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A review reason is required.']);
        }

        if (! in_array($status, ['explained', 'inconclusive'], true)) {
            throw ValidationException::withMessages(['status' => 'The requested review outcome is not supported.']);
        }

        DB::transaction(function () use ($case, $actor, $status, $reason, $corroboratingReference): void {
            $lockedCase = WorkerComplianceReviewCase::query()->lockForUpdate()->findOrFail($case->getKey());
            $this->assertCurrentReviewer($lockedCase, $actor);

            $from = $lockedCase->status;
            $expiresAt = now()->addDays((int) config('cleaning.worker_compliance.review_case_retention_days', 90));
            $lockedCase->forceFill([
                'status' => $status,
                'expires_at' => $expiresAt,
            ])->save();
            $this->recordEvent($lockedCase, $actor, $status, $from, $status, $reason, $corroboratingReference, null);
        });
    }

    public function confirmViolation(
        WorkerComplianceReviewCase $case,
        User $actor,
        string $reason,
        string $independentEvidenceReference,
        DateTimeInterface $workerResponseOpportunityAt,
    ): void {
        Gate::forUser($actor)->authorize('confirm', $case);

        if (mb_trim($reason) === '' || mb_trim($independentEvidenceReference) === '') {
            throw ValidationException::withMessages(['independentEvidenceReference' => 'A review reason and independent evidence reference are required.']);
        }

        if ($workerResponseOpportunityAt > now()) {
            throw ValidationException::withMessages(['workerResponseOpportunityAt' => 'The response opportunity must have occurred already.']);
        }

        DB::transaction(function () use ($case, $actor, $reason, $independentEvidenceReference, $workerResponseOpportunityAt): void {
            $lockedCase = WorkerComplianceReviewCase::query()->lockForUpdate()->findOrFail($case->getKey());

            if ($lockedCase->status !== 'under_review') {
                throw ValidationException::withMessages(['status' => 'Only an open review may be confirmed. Refresh the case.']);
            }

            if ($lockedCase->claimed_by_user_id === $actor->getKey()) {
                throw ValidationException::withMessages(['reviewer' => 'A different authorized reviewer must confirm this case.']);
            }

            if ($workerResponseOpportunityAt < $lockedCase->created_at) {
                throw ValidationException::withMessages(['workerResponseOpportunityAt' => 'The worker must have an opportunity to respond after the case was opened.']);
            }

            $from = $lockedCase->status;
            $lockedCase->forceFill([
                'status' => 'confirmed_violation',
                'expires_at' => now()->addDays((int) config('cleaning.worker_compliance.review_case_retention_days', 90)),
            ])->save();
            $this->recordEvent(
                $lockedCase,
                $actor,
                'confirmed_violation',
                $from,
                'confirmed_violation',
                $reason,
                null,
                $independentEvidenceReference,
                $workerResponseOpportunityAt,
            );
        });
    }

    public function recordEvidenceView(WorkerComplianceReviewCase $case, User $actor): void
    {
        Gate::forUser($actor)->authorize('view', $case);

        $this->recordEvent($case, $actor, 'evidence_viewed', $case->status, $case->status, null, null, null);
    }

    private function assertCurrentReviewer(WorkerComplianceReviewCase $case, User $actor): void
    {
        if ($case->status !== 'under_review' || (int) $case->claimed_by_user_id !== (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => 'Claim the current case before recording a review outcome.']);
        }
    }

    private function recordEvent(
        WorkerComplianceReviewCase $case,
        User $actor,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $reason,
        ?string $corroboratingReference,
        ?string $independentEvidenceReference,
        ?DateTimeInterface $workerResponseOpportunityAt = null,
    ): void {
        DB::table('worker_compliance_review_events')->insert([
            'case_id' => $case->getKey(),
            'case_reference' => $case->public_reference,
            'actor_user_id' => $actor->getKey(),
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'corroborating_reference' => $corroboratingReference,
            'independent_evidence_reference' => $independentEvidenceReference,
            'worker_response_opportunity_at' => $workerResponseOpportunityAt,
            'expires_at' => now()->addDays((int) config('cleaning.worker_compliance.review_audit_retention_days', 180)),
            'created_at' => now(),
        ]);
    }
}
