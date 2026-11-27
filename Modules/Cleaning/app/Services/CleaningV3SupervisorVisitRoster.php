<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** The assigned supervisor's visit-scoped crew, never the legacy parent roster. */
final class CleaningV3SupervisorVisitRoster
{
    public function project(CleaningBooking $booking): array
    {
        $policy = app(CleaningV3OperationalAdmissionPolicy::class);
        if ($booking->contract_version !== 'v3' || ! $policy->approved($booking)) {
            return [];
        }
        return $booking->sessions()->where('supervision_mode', CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR)
            ->where('status', '!=', 'superseded')
            ->with('workerAssignments.worker.user')->orderBy('sequence')->get()
            ->filter(fn ($visit) => $policy->approved($booking, $visit))
            ->map(function (CleaningBookingSession $visit) use ($booking): array {
                $visit->setRelation('booking', $booking);
                $workers = $visit->workerAssignments->filter(fn ($assignment) =>
                    $assignment->released_at === null
                    && in_array($assignment->status->value, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true));
                $clock = null;
                $actor = auth()->user();
                if (app(CleaningV3WorkClockAccessPolicy::class)->canRead($actor, $booking, $visit)) {
                    try {
                        $clock = app(CleaningV3WorkClockProjectionService::class)->project($visit);
                    } catch (\DomainException) {
                        // No inferred time or clock actions for inconsistent history.
                    }
                }
                $actions = app(CleaningV3SupervisedVisitService::class)->allowedActions($actor, $booking, $visit, $clock);
                if ($clock !== null && ! $visit->isTerminal()
                    && app(CleaningV3WorkClockAccessPolicy::class)->canDecide($actor, $booking, $visit)) {
                    if ($clock['state'] === 'running' && $clock['pausePending']) {
                        $actions = [...$actions, 'approve_pause', 'reject_pause'];
                    }
                    if ($clock['state'] === 'paused'
                        && $workers->filter(fn ($assignment) => $assignment->isActive())->count() >= $visit->requiredWorkerCount()
                        && $clock['activeSeconds'] < (int) min(480, ceil((float) $visit->duration_hours * 60)) * 60) {
                        $actions[] = 'resume_work';
                    }
                    if ($clock['state'] === 'paused') {
                        try {
                            $extension = app(CleaningV3LiveExtensionService::class)->context((int) $booking->id, (int) $visit->id, $actor);
                            if ($extension['canDecide'] || in_array($extension['offer']['status'] ?? null, ['customer_confirmed', 'crew_approved'], true)) {
                                $actions[] = 'view_extension';
                            }
                        } catch (\DomainException | \Symfony\Component\HttpKernel\Exception\HttpException) {
                            // An unavailable extension context cannot enable a command.
                        }
                    }
                }
                return [
                    'visitId' => (int) $visit->id,
                    'sequence' => (int) $visit->sequence,
                    'status' => $visit->status->value,
                    'scheduledDate' => $visit->scheduled_date?->format('Y-m-d'),
                    'scheduledTime' => mb_substr((string) $visit->scheduled_time, 0, 5),
                    'requiredWorkers' => $visit->requiredWorkerCount(),
                    'acceptedWorkers' => $workers->count(),
                    'physicallyArrivedWorkers' => $workers->whereNotNull('arrived_at')->count(),
                    'customerVerifiedWorkers' => $workers->whereNotNull('start_approved_at')->count(),
                    'verificationStatus' => in_array($visit->status->value, ['awaiting_start_verification', 'awaiting_worker_start_confirmation'], true)
                        ? app(CleaningV3SupervisedVisitService::class)->verificationStatus((int) $visit->id) : null,
                    'allowedActions' => array_values(array_unique($actions)),
                    'clock' => $clock === null ? null : [
                        'state' => $clock['state'], 'activeSeconds' => $clock['activeSeconds'],
                    ],
                    'workers' => $workers->map(fn ($assignment) => [
                        'assignmentId' => (int) $assignment->id,
                        'workerId' => (int) $assignment->worker_id,
                        'name' => $assignment->worker?->user?->name,
                        'phone' => $assignment->worker?->user?->phone,
                        'status' => $assignment->status->value,
                        'arrivedAt' => $assignment->arrived_at?->toIso8601String(),
                        'customerVerifiedAt' => $assignment->start_approved_at?->toIso8601String(),
                    ])->values()->all(),
                ];
            })->values()->all();
    }
}
