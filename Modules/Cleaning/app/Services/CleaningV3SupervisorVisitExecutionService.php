<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus as WorkerStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Compatibility projection over the single approved supervisor execution workflow. */
final class CleaningV3SupervisorVisitExecutionService
{
    public function __construct(private readonly CleaningV3SupervisedVisitService $visits) {}

    public function state(int $bookingId, int $visitId, User $actor): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $actor): array {
            $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            $visit = $booking->sessions()->whereKey($visitId)->lockForUpdate()->firstOrFail();
            abort_unless($booking->contract_version === 'v3'
                && $visit->supervision_mode === CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
                && app(CleaningV3OperationalAdmissionPolicy::class)->approved($booking, $visit), 404);
            $supervisor = app(CleaningSupervisorAssignmentService::class)->activeSupervisorId($booking);
            $customer = $actor->is_active && (int) $actor->id === (int) $booking->customer_id;
            $assigned = $actor->is_active && $actor->hasRole(SupervisedTeamService::ROLE)
                && $supervisor === (int) $actor->id;
            abort_unless($customer || $assigned, 404);

            $roster = $visit->workerAssignments()->whereNull('released_at')
                ->whereIn('status', WorkerStatus::acceptedValues())->orderBy('id')->get();
            $clock = null;
            if ($assigned && app(CleaningV3WorkClockAccessPolicy::class)->canRead($actor, $booking, $visit)) {
                try {
                    $clock = app(CleaningV3WorkClockProjectionService::class)->project($visit);
                } catch (\DomainException) {
                    // Inconsistent clock history must not enable handover.
                }
            }
            $actions = $assigned ? $this->visits->allowedActions($actor, $booking, $visit, $clock) : [];
            $verification = $this->visits->verificationStatus($visitId);
            return [
                'visitId' => $visitId, 'status' => $visit->status->value,
                'requiredWorkers' => $visit->requiredWorkerCount(),
                'acceptedWorkers' => $roster->count(),
                'arrivedWorkers' => $roster->whereNotNull('arrived_at')->count(),
                'canIssueCode' => $customer && $verification === 'pending',
                'canRequestVerification' => in_array('request_verification', $actions, true),
                'canVerify' => in_array('confirm_verification', $actions, true),
                'canStart' => in_array('start_work', $actions, true),
                'canFinish' => in_array('request_handover', $actions, true),
                'workers' => $assigned ? $roster->load('worker.user')->map(fn ($a) => [
                    'workerId' => (int) $a->worker_id, 'name' => $a->worker?->user?->name,
                    'phone' => $a->worker?->user?->phone, 'status' => $a->status->value,
                    'arrived' => $a->arrived_at !== null,
                ])->all() : [],
            ];
        }, 3);
    }

    public function request(int $bookingId, int $visitId, User $actor): array
    {
        $this->visits->requestVerification($actor, $bookingId, $visitId);
        return $this->state($bookingId, $visitId, $actor);
    }

    public function issue(int $bookingId, int $visitId, User $actor): array
    {
        return $this->visits->customerCode($actor, $bookingId, $visitId);
    }

    public function verify(int $bookingId, int $visitId, User $actor, string $code): array
    {
        $this->visits->confirmVerification($actor, $bookingId, $visitId, $code);
        return $this->state($bookingId, $visitId, $actor);
    }

    public function start(int $bookingId, int $visitId, User $actor): array
    {
        $this->visits->start($actor, $bookingId, $visitId);
        return $this->state($bookingId, $visitId, $actor);
    }

    public function finish(int $bookingId, int $visitId, User $actor, ?array $report = null): array
    {
        $this->visits->requestHandover($actor, $bookingId, $visitId, $report);
        return $this->state($bookingId, $visitId, $actor);
    }
}
