<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** One current non-event execution. Historical groups keep their compatibility contract. */
final class CleaningV3DailyBookingProjection
{
    public function execution(CleaningBooking $booking): ?CleaningBookingSession
    {
        if (($booking->getAttributes()['contract_version'] ?? null) !== 'v3' || $booking->isEventAssistanceBooking()) {
            return null;
        }
        $sessions = $booking->sessions()->where('status', '!=', 'superseded')->limit(2)->get();
        if ($sessions->count() !== 1) {
            return null;
        }
        $visit = $sessions->first();
        $visit->setRelation('booking', $booking);
        return $visit;
    }

    public function present(CleaningBooking $booking, ?User $actor = null, ?array $schedule = null): ?array
    {
        $visit = $this->execution($booking);
        if ($visit === null) {
            return null;
        }
        $access = app(CleaningV3WorkClockAccessPolicy::class);
        $admitted = app(CleaningV3OperationalAdmissionPolicy::class)->approved($booking, $visit);
        $dispatch = app(CleaningV3VisitDispatchService::class)->project($visit);
        $status = $visit->status->value;
        $terminal = $visit->isTerminal();
        $state = match (true) {
            $terminal => $status,
            ! $admitted => 'pending_operational_review',
            in_array($status, ['scheduled', 'worker_assigned'], true)
                && in_array($dispatch['dispatchStatus'], ['searching', 'escalated'], true) => 'searching',
            in_array($status, ['scheduled', 'worker_assigned'], true)
                && $dispatch['dispatchStatus'] === 'reschedule_required' => 'reschedule_required',
            $status === 'scheduled' && $dispatch['dispatchStatus'] === 'covered' => 'worker_assigned',
            $status === 'worker_assigned' && $visit->arrived_at !== null => 'arrived',
            $status === 'worker_assigned' && $visit->started_travel_at !== null => 'travelling',
            default => $status,
        };

        $actions = [];
        $clock = null;
        $customer = $access->isCustomer($actor, $booking);
        if ($customer && $this->canCancelHeld($booking, $visit)) {
            $actions[] = 'cancel_booking';
        }
        if ($admitted && $access->canRead($actor, $booking, $visit)) {
            try {
                $clock = app(CleaningV3WorkClockProjectionService::class)->project($visit);
            } catch (DomainException) {
                // Corrupt/missing interval history is not a fabricated timer.
                $clock = null;
            }
            if (! $terminal) {
                if ($customer) {
                    if ($status === 'in_progress' && $clock !== null && $clock['state'] === 'running') {
                        $actions[] = $clock['pausePending'] ? 'withdraw_pause' : 'request_pause';
                    }
                    if ($status === 'awaiting_customer_completion') {
                        $actions[] = 'confirm_completion';
                    }
                } elseif ($access->canDecide($actor, $booking, $visit) && $clock !== null) {
                    if ($status === 'in_progress' && $clock['state'] === 'running' && $clock['pausePending']) {
                        $actions = [...$actions, 'approve_pause', 'reject_pause'];
                    }
                    if ($status === 'paused' && $clock['state'] === 'paused'
                        && $visit->workerAssignments()->whereNull('released_at')
                            ->whereIn('status', \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::activeValues())->count() >= $visit->requiredWorkerCount()
                        && $clock['activeSeconds'] < (int) min(480, ceil((float) $visit->duration_hours * 60)) * 60) {
                        $actions[] = 'resume_work';
                    }
                }
            }
        }
        if ($status === 'completed' && $clock !== null && ! DB::table('cleaning_work_intervals')
            ->where('cleaning_booking_session_id', $visit->id)->exists()) {
            // Historical records without an interval history have no measured
            // duration. A projected zero is not evidence of elapsed work.
            $clock = null;
        }
        if ($admitted && ! $terminal) {
            $actions = [...$actions, ...app(CleaningV3SupervisedVisitService::class)->allowedActions($actor, $booking, $visit, $clock)];
        }
        // The extension dialog re-reads the signed quote/decision context.
        // Opening it is distinct from authority to quote, confirm or apply.
        if ($admitted && ! $terminal && $clock !== null && $clock['state'] === 'paused'
            && ($customer || $access->canDecide($actor, $booking, $visit))) {
            try {
                $extension = app(CleaningV3LiveExtensionService::class)->context(
                    (int) $booking->id, (int) $visit->id, $actor);
                if ($extension['canQuote'] || $extension['canConfirm'] || $extension['canDecide']
                    || in_array($extension['offer']['status'] ?? null, ['customer_confirmed', 'crew_approved'], true)) {
                    $actions[] = 'view_extension';
                }
            } catch (DomainException | \Symfony\Component\HttpKernel\Exception\HttpException) {
                // No actionable extension context on stale or revoked scope.
            }
        }
        $membership = null;
        $myPayable = null;
        $worker = $actor?->worker;
        if (! $customer && $worker instanceof \App\Models\Worker
            && (int) $worker->user_id === (int) $actor->id && $actor->is_active
            && $admitted && app(CleaningBookingWorkerSessionVisibilityService::class)->canViewSession($booking, $visit, $worker)) {
            $assignment = $visit->workerAssignments()->where('worker_id', $worker->id)
                ->whereNull('released_at')
                ->whereIn('status', \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::acceptedValues())->first();
            if ($assignment !== null) {
                $membership = ['executionId' => (int) $visit->id,
                    'sessionAssignmentId' => (int) $assignment->id, 'status' => $assignment->status->value];
                $exact = (string) $assignment->getRawOriginal('worker_amount');
                if ($assignment->currency === 'SYP' && preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/D', $exact)) {
                    $myPayable = ['executionId' => (int) $visit->id, 'sessionAssignmentId' => (int) $assignment->id,
                        'kind' => 'assignment', 'amountSyp' => bcadd($exact, '0', 2)];
                }
                if (! $terminal && $assignment->isActive()) {
                    if ($visit->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
                        && $status === 'awaiting_start_verification' && $assignment->arrived_at !== null
                        && $assignment->start_approved_at === null && $assignment->work_started_at === null) {
                        $actions[] = 'view_start_code';
                    }
                    if (in_array($status, ['scheduled', 'worker_assigned', 'awaiting_start_verification'], true)
                        && $assignment->started_travel_at !== null && $assignment->arrived_at === null) {
                        $actions[] = 'view_destination';
                    }
                }
            }
        }
        // Both list/detail and schedule consumers use the same capability
        // source. Do not derive worker actions from a customer schedule.
        if ($customer && $schedule === null) {
            $schedule = app(CleaningBookingCustomerScheduleService::class)->present($booking);
        }
        if (count($schedule['sessions'] ?? []) === 1) {
            $session = $schedule['sessions'][0];
            if ((int) ($session['sessionId'] ?? $session['id'] ?? 0) === (int) $visit->id) {
                foreach ([
                    'canAccept' => 'accept', 'canStartTravel' => 'start_travel', 'canArrive' => 'arrive',
                    'canStartWork' => 'start_work', 'canComplete' => 'request_completion',
                    'canConfirmStartVerification' => 'confirm_start_verification', 'canViewSupervisorCode' => 'view_supervisor_code',
                    'canConfirmCompletion' => 'confirm_completion', 'canRejectCompletion' => 'reject_completion',
                    'canReview' => 'review',
                ] as $flag => $action) {
                    if (($session[$flag] ?? false) === true && ($admitted || $action === 'review')
                        && (! $terminal || $action === 'review')) {
                        $actions[] = $action;
                    }
                }
                if ($worker instanceof \App\Models\Worker && $membership === null && in_array('accept', $actions, true)) {
                    $preview = $session['pricingPreview'] ?? null;
                    $exact = $preview['workerAmountSyp'] ?? null;
                    if (is_string($exact) && preg_match('/^(?:0|[1-9][0-9]{0,15})\.[0-9]{2}$/D', $exact)) {
                        $myPayable = ['executionId' => (int) $visit->id, 'sessionAssignmentId' => null,
                            'kind' => 'offer', 'amountSyp' => $exact];
                    }
                }
            }
        }

        return [
            'bookingId' => (int) $booking->id,
            'executionId' => (int) $visit->id,
            'status' => $status,
            'state' => $state,
            'scheduledDate' => $visit->scheduled_date?->format('Y-m-d'),
            'scheduledTime' => mb_substr((string) $visit->scheduled_time, 0, 5),
            'durationMinutes' => (int) round((float) $visit->duration_hours * 60),
            'requiredWorkers' => $dispatch['requiredWorkers'],
            'acceptedWorkers' => $dispatch['acceptedWorkers'],
            'remainingWorkers' => $dispatch['remainingWorkers'],
            'dispatchStatus' => $dispatch['dispatchStatus'],
            'searchActive' => ! $terminal && $admitted && $dispatch['remainingWorkers'] > 0
                && in_array($dispatch['dispatchStatus'], ['searching', 'escalated'], true),
            'clock' => $clock !== null && ! in_array($clock['state'], ['not_started', 'cancelled', 'skipped', 'superseded'], true) ? $clock : null,
            'allowedActions' => array_values(array_unique($actions)),
            ...($worker instanceof \App\Models\Worker && ! $customer ? ['myMembership' => $membership, 'myPayable' => $myPayable] : []),
            'priceRevision' => (int) $visit->price_revision,
            'serverNow' => $dispatch['serverNow'],
        ];
    }

    public function canCancelHeld(CleaningBooking $booking, CleaningBookingSession $visit): bool
    {
        return $booking->status->value === 'pending'
            && data_get($booking->property_details, 'v3_admission_hold') === true
            && $visit->status->value === 'scheduled'
            && ! $visit->workerAssignments()->exists()
            && ! DB::table('cleaning_v3_cash_movements')->where('cleaning_booking_session_id', $visit->id)->exists()
            && ! DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)->exists()
            && ! DB::table('cleaning_v3_visit_dispatches')->where('cleaning_booking_session_id', $visit->id)->exists()
            && ! DB::table('cleaning_equipment_reservations')->where('cleaning_booking_id', $booking->id)
                ->whereNotIn('status', ['reserved', 'released'])->exists()
            && ! DB::table('cleaning_visit_funding_reservations')->where('active_visit_id', $visit->id)->exists();
    }
}
