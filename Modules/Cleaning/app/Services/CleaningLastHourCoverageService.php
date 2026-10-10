<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingRoomAssignmentSource;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

/**
 * Last-hour decision is independent from internal geographic worker discovery.
 * Never alter an accepted worker's task scope without an explicit customer decision.
 */
final class CleaningLastHourCoverageService
{
    public function __construct(
        private readonly CleaningBookingScheduledAtResolver $schedule,
        private readonly CleaningBookingTeamService $teams,
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    public function decision(CleaningBooking $booking, ?CarbonInterface $clock = null): array
    {
        $start = $this->schedule->resolve($booking);
        $now = $clock ?? now();
        $accepted = $booking->acceptedWorkerAssignments()->get();
        $required = max(1, (int) ($booking->number_of_workers ?? 1));
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value : (string) $booking->status;
        $isLegacyOneTime = ! $booking->sessions()->exists()
            && (string) ($booking->booking_kind ?? 'standard') === 'standard';
        $withinLastHour = $start !== null
            && $now->greaterThanOrEqualTo($start->subHour())
            && $now->lessThan($start);
        $eligible = $isLegacyOneTime
            && $withinLastHour
            && $status === CleaningBookingStatus::Pending->value
            && $accepted->count() > 0
            && $accepted->count() < $required
            && $booking->last_hour_team_decision === null;

        return [
            'required' => $eligible,
            'acceptedWorkers' => $accepted->count(),
            'requiredWorkers' => $required,
            'workerIds' => $eligible ? $accepted->pluck('worker_id')->map(fn ($id): int => (int) $id)->values()->all() : [],
            'decision' => $booking->last_hour_team_decision,
        ];
    }

    /** @return array{cancelled:int,prompted:int} */
    public function processDue(?CarbonInterface $clock = null): array
    {
        $now = $clock ?? now();
        $result = ['cancelled' => 0, 'prompted' => 0];
        CleaningBooking::query()
            ->where('status', CleaningBookingStatus::Pending->value)
            ->whereDate('scheduled_date', '<=', $now->copy()->addDay()->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($bookings) use ($now, &$result): void {
                foreach ($bookings as $booking) {
                    $action = DB::transaction(function () use ($booking, $now): ?string {
                        $locked = CleaningBooking::query()->whereKey($booking->id)
                            ->lockForUpdate()->first();
                        if (! $locked || $locked->status !== CleaningBookingStatus::Pending) {
                            return null;
                        }
                        if ($locked->sessions()->exists()
                            || (string) ($locked->booking_kind ?? 'standard') !== 'standard') {
                            return null;
                        }
                        $start = $this->schedule->resolve($locked);
                        if ($start === null || $now->lessThan($start->subHour())) {
                            return null;
                        }
                        $accepted = $locked->acceptedWorkerAssignments()->count();
                        if ($accepted === 0) {
                            $locked->forceFill([
                                'status' => CleaningBookingStatus::Cancelled,
                                'cancelled_at' => $now,
                                'cancelled_by_role' => 'system',
                                'cancellation_reason' => 'لم يتوفر عامل قبل موعد الخدمة بساعة.',
                            ])->save();
                            return 'cancelled';
                        }
                        if ($accepted < max(1, (int) ($locked->number_of_workers ?? 1))
                            && $now->lessThan($start)
                            && $locked->last_hour_team_decision === null
                            && $locked->last_hour_team_prompted_at === null) {
                            $locked->forceFill(['last_hour_team_prompted_at' => $now])->save();
                            return 'prompted';
                        }
                        return null;
                    });

                    if ($action !== null) {
                        $result[$action]++;
                        $fresh = $booking->fresh(['customer']);
                        if ($action === 'cancelled') {
                            app(CleaningMaterialInventoryService::class)->releaseForBooking($fresh);
                            $this->notifications->notifyCustomer(
                                booking: $fresh,
                                canonicalType: 'cleaning.booking.order_cancelled',
                                action: 'system_no_workers_one_hour_before',
                                actorRole: 'system',
                                extraData: ['cancellationReason' => $fresh->cancellation_reason],
                            );
                        } else {
                            $this->notifications->notifyCustomer(
                                booking: $fresh,
                                canonicalType: 'cleaning.booking.last_hour_team_decision_required',
                                action: 'last_hour_team_decision_required',
                                actorRole: 'system',
                            );
                        }
                    }
                }
            });
        return $result;
    }

    public function decide(CleaningBooking $booking, string $choice, ?int $workerId): CleaningBooking
    {
        if (! in_array($choice, ['all_tasks', 'assigned_only'], true)) {
            throw ValidationException::withMessages(['choice' => ['Invalid team coverage decision.']]);
        }

        $updated = DB::transaction(function () use ($booking, $choice, $workerId): CleaningBooking {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $state = $this->decision($locked);
            if (! $state['required']) {
                throw ValidationException::withMessages(['choice' => ['The last-hour decision is no longer available.']]);
            }

            $accepted = $locked->acceptedWorkerAssignments()->lockForUpdate()->get();
            if ($choice === 'all_tasks') {
                if ($workerId === null || ! $accepted->contains('worker_id', $workerId)) {
                    throw ValidationException::withMessages(['workerId' => ['Choose an accepted worker.']]);
                }
                if ($accepted->count() !== 1) {
                    throw ValidationException::withMessages(['choice' => ['All tasks can be combined only when one worker has accepted.']]);
                }

                // Update planned and effective room ownership together, including
                // rooms awaiting a future worker. This is the customer's explicit request.
                CleaningBookingRoom::query()
                    ->where('cleaning_booking_id', $locked->id)
                    ->update([
                        'assigned_worker_id' => $workerId,
                        'planned_worker_slot' => 1,
                        'planned_preferred_worker_id' => $workerId,
                        'assignment_source' => CleaningBookingRoomAssignmentSource::Customer->value,
                    ]);
                $locked->forceFill([
                    'number_of_workers' => 1,
                    'last_hour_team_decision' => $choice,
                    'last_hour_team_worker_id' => $workerId,
                    'last_hour_team_decided_at' => now(),
                ])->save();
                $locked = $this->teams->recalculateBookingTeam(
                    $locked, finalizeBooking: true, applyPlannedAssignments: false,
                );
            } else {
                // Keep looking for the missing workers. Do not silently expand
                // the existing worker's tasks or finalize an incomplete team.
                $locked->forceFill([
                    'last_hour_team_decision' => $choice,
                    'last_hour_team_decided_at' => now(),
                ])->save();
            }

            return $locked->fresh(['customer', 'workerAssignments.worker.user', 'rooms.assignedWorker.user']);
        });

        if ($choice === 'all_tasks') {
            $this->notifications->notifyWorkerById(
                booking: $updated,
                workerId: (int) $workerId,
                canonicalType: 'cleaning.booking.last_hour_tasks_expanded',
                action: 'customer_assigned_all_tasks',
                actorRole: 'customer',
                extraData: ['allTasksAssigned' => true, 'refreshBooking' => true],
            );
        }
        $this->notifications->notifyCustomer(
            booking: $updated,
            canonicalType: 'cleaning.booking.updated',
            action: 'last_hour_team_decision_'.$choice,
            actorRole: 'customer',
        );
        return $updated;
    }
}
