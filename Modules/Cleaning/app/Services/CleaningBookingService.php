<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\GenderPreference;
use App\Enums\SystemAlertStatus;
use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\Dispute;
use App\Models\SystemAlert;
use App\Models\Worker;
use App\Support\Broadcast\BroadcastAfterResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Data\CleaningBookingData;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CleaningOrderAwaitingCustomerCompletion;
use Modules\Cleaning\Events\CleaningOrderAwaitingStartVerification;
use Modules\Cleaning\Events\WorkerArrived;
use Modules\Cleaning\Events\WorkerLocationUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

final class CleaningBookingService
{
    private const SECURITY_CODE_TTL_MINUTES = 10;

    private const SECURITY_CODE_LENGTH = 4;

    private const FINISH_TYPE_SUCCESS = 'success';

    private const FINISH_TYPE_DISPUTE = 'dispute';

    private const MANUAL_REVIEW_MESSAGE = 'The booking has been paused for manual admin review.';

    public function __construct(
        private readonly CleaningLifecycleNotificationService $lifecycleNotifications,
        private readonly CleaningBookingTeamService $teamService,
        private readonly WorkerTrustService $workerTrustService,
        private readonly DepositService $depositService,
    ) {}

    public function store(CleaningBookingData $data): CleaningBooking
    {
        return DB::transaction(function () use ($data): CleaningBooking {
            $attributes = $data->onlyModelAttributes();
            $attributes['gender_preference'] = $attributes['gender_preference'] ?? GenderPreference::Any->value;
            $attributes['number_of_workers'] = $attributes['number_of_workers'] ?? 1;

            $booking = CleaningBooking::create($attributes);

            $this->teamService->syncRooms($booking);

            return $this->freshBooking($booking);
        });
    }

    public function update(CleaningBookingData $data, CleaningBooking $booking): CleaningBooking
    {
        return DB::transaction(function () use ($data, $booking): CleaningBooking {
            $attributes = $data->onlyModelAttributes();
            if (array_key_exists('gender_preference', $attributes) && $attributes['gender_preference'] === null) {
                $attributes['gender_preference'] = GenderPreference::Any->value;
            }
            if (array_key_exists('number_of_workers', $attributes) && $attributes['number_of_workers'] === null) {
                $attributes['number_of_workers'] = 1;
            }

            tap($booking)->update($attributes);

            if (array_intersect(array_keys($attributes), ['property_type', 'property_details']) !== [] && ! $booking->acceptedWorkerAssignments()->exists()) {
                $this->teamService->syncRooms($booking->fresh());
            }

            return $this->freshBooking($booking);
        });
    }

    public function accept(CleaningBooking $booking, ?array $roomIds = null): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        $updated = DB::transaction(function () use ($booking, $roomIds): CleaningBooking {
            $worker = Auth::user()?->worker;
            if (! $worker) {
                throw new InvalidArgumentException('User must have an associated worker.');
            }

            return $this->teamService->acceptWorker($booking, $worker, $roomIds);
        });

        $this->dispatchTrackingUpdate($updated);
        if ($updated->status === CleaningBookingStatus::WorkerAssigned) {
            $this->lifecycleNotifications->notifyCustomer(
                booking: $updated,
                canonicalType: 'cleaning.booking.worker_assigned',
                action: 'worker_assigned',
                actorRole: 'worker',
                fromStatus: $fromStatus,
                occurredAt: $updated->updated_at?->toIso8601String(),
            );
        } else {
            $this->lifecycleNotifications->notifyCustomer(
                booking: $updated,
                canonicalType: 'cleaning.booking.worker_confirmed',
                action: 'worker_confirmed',
                actorRole: 'worker',
                fromStatus: $fromStatus,
                occurredAt: $updated->updated_at?->toIso8601String(),
            );
        }

        return $updated;
    }

    public function claimRooms(CleaningBooking $booking, ?array $roomIds = null): CleaningBooking
    {
        $worker = Auth::user()?->worker;
        if (! $worker) {
            throw new InvalidArgumentException('User must have an associated worker.');
        }

        $updated = $this->teamService->claimRooms($booking, $worker, $roomIds);

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    public function reject(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        $updated = DB::transaction(function () use ($booking, $reason): CleaningBooking {
            $worker = Auth::user()?->worker;
            if (! $worker) {
                throw new InvalidArgumentException('User must have an associated worker.');
            }

            return $this->teamService->rejectWorker($booking, $worker, $reason);
        });

        NotifyEligibleWorkersNewOrderJob::dispatch($updated->id);

        $this->dispatchTrackingUpdate($updated);

        if ($updated->status === CleaningBookingStatus::Cancelled) {
            $this->lifecycleNotifications->notifyCustomer(
                booking: $updated,
                canonicalType: 'cleaning.booking.order_cancelled',
                action: 'worker_rejected',
                actorRole: 'worker',
                fromStatus: $fromStatus,
                occurredAt: $updated->cancelled_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
            );
        }

        return $updated;
    }

    public function startTravel(CleaningBooking $booking): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        $updated = DB::transaction(function () use ($booking): CleaningBooking {
            $worker = $this->currentWorker();
            $lockedBooking = $this->lockBooking($booking);

            if (! in_array($lockedBooking->status, [
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::AwaitingStartVerification,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation,
            ], true)) {
                throw new InvalidArgumentException('Booking cannot start travel in current status.');
            }

            $assignment = $this->activeAssignmentForWorker($lockedBooking->id, $worker->id, true);

            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                if ($assignment->started_travel_at === null) {
                    $assignment->forceFill([
                        'started_travel_at' => now(),
                    ])->save();
                }

                if ($lockedBooking->started_travel_at === null) {
                    $lockedBooking->forceFill(['started_travel_at' => $assignment->started_travel_at ?? now()])->save();
                }

                return $this->freshBooking($lockedBooking);
            }

            if ((int) $lockedBooking->worker_id !== (int) $worker->id) {
                throw new InvalidArgumentException('Worker must accept the booking before starting travel.');
            }

            $lockedBooking->update(['started_travel_at' => now()]);

            return $this->freshBooking($lockedBooking);
        });

        $this->dispatchTrackingUpdate($updated);
        $this->lifecycleNotifications->notifyCustomer(
            booking: $updated,
            canonicalType: 'cleaning.booking.worker_started_travel',
            action: 'worker_started_travel',
            actorRole: 'worker',
            fromStatus: $fromStatus,
            occurredAt: $updated->started_travel_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    /**
     * @return array{securityCode: string, expiresAt: string}
     */
    public function issueSecurityCode(CleaningBooking $booking): array
    {
        return DB::transaction(function () use ($booking): array {
            $worker = $this->currentWorker();
            $lockedBooking = $this->lockBooking($booking);

            if (! in_array($lockedBooking->status, [
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::AwaitingStartVerification,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation,
            ], true)) {
                throw new InvalidArgumentException('Security code is only available for bookings ready to start.');
            }

            $assignment = $this->activeAssignmentForWorker($lockedBooking->id, $worker->id, true);
            if ($assignment instanceof CleaningBookingWorkerAssignment && $assignment->arrived_at === null) {
                throw new InvalidArgumentException('Worker must arrive before requesting a security code.');
            }

            if (! $assignment instanceof CleaningBookingWorkerAssignment && (int) $lockedBooking->worker_id !== (int) $worker->id) {
                throw new InvalidArgumentException('Worker must accept the booking before requesting a security code.');
            }

            $securityCode = mb_str_pad((string) random_int(0, 9999), self::SECURITY_CODE_LENGTH, '0', STR_PAD_LEFT);
            $expiresAt = now()->addMinutes(self::SECURITY_CODE_TTL_MINUTES);

            DB::table('booking_security_codes')->updateOrInsert(
                [
                    'booking_id' => $lockedBooking->id,
                    'booking_type' => $lockedBooking->getMorphClass(),
                ],
                [
                    'code' => $this->securityCodeHash($securityCode),
                    'code_hash' => $this->securityCodeHash($securityCode),
                    'attempts' => 0,
                    'expires_at' => $expiresAt,
                    'consumed_at' => null,
                    'last_attempt_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return [
                'securityCode' => $securityCode,
                'expiresAt' => $expiresAt->toIso8601String(),
            ];
        });
    }

    public function updateLocation(CleaningBooking $booking, float $latitude, float $longitude): void
    {
        $worker = $this->currentWorker();
        $assignment = $this->activeAssignmentForWorker($booking->id, $worker->id);

        if ($assignment instanceof CleaningBookingWorkerAssignment) {
            if ($assignment->started_travel_at === null) {
                throw new InvalidArgumentException('Worker must have started travel to send location updates.');
            }

            BroadcastAfterResponse::send(new WorkerLocationUpdated($booking->id, $latitude, $longitude, $worker->id));

            return;
        }

        if ($booking->status !== CleaningBookingStatus::WorkerAssigned || $booking->started_travel_at === null) {
            throw new InvalidArgumentException('Worker must have started travel to send location updates.');
        }

        if ((int) $booking->worker_id !== (int) $worker->id) {
            throw new InvalidArgumentException('Only the assigned worker can update location.');
        }

        BroadcastAfterResponse::send(new WorkerLocationUpdated($booking->id, $latitude, $longitude, $worker->id));
    }

    public function arrive(CleaningBooking $booking): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        $updated = DB::transaction(function () use ($booking): CleaningBooking {
            $worker = $this->currentWorker();
            $lockedBooking = $this->lockBooking($booking);

            if (! in_array($lockedBooking->status, [
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::AwaitingStartVerification,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation,
            ], true)) {
                throw new InvalidArgumentException('Booking must be ready to start before marking arrival.');
            }

            $assignment = $this->activeAssignmentForWorker($lockedBooking->id, $worker->id, true);

            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                if ($assignment->started_travel_at === null) {
                    throw new InvalidArgumentException('Worker must have started travel before marking arrival.');
                }

                $arrivedAt = now();
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification,
                    'arrived_at' => $assignment->arrived_at ?? $arrivedAt,
                ])->save();

                $updates = [];
                if ($lockedBooking->status === CleaningBookingStatus::WorkerAssigned) {
                    $updates['status'] = CleaningBookingStatus::AwaitingStartVerification;
                }
                if ($lockedBooking->arrived_at === null) {
                    $updates['arrived_at'] = $assignment->arrived_at ?? $arrivedAt;
                }
                if ($updates !== []) {
                    $lockedBooking->forceFill($updates)->save();
                }

                return $this->freshBooking($lockedBooking);
            }

            if ((int) $lockedBooking->worker_id !== (int) $worker->id) {
                throw new InvalidArgumentException('Worker must accept the booking before marking arrival.');
            }

            if ($lockedBooking->started_travel_at === null) {
                throw new InvalidArgumentException('Worker must have started travel before marking arrival.');
            }

            $lockedBooking->update([
                'status' => CleaningBookingStatus::AwaitingStartVerification,
                'arrived_at' => now(),
            ]);

            return $this->freshBooking($lockedBooking);
        });

        BroadcastAfterResponse::send(new WorkerArrived($updated->id, (string) $updated->arrived_at?->toIso8601String()));
        BroadcastAfterResponse::send(new CleaningOrderAwaitingStartVerification(
            $updated->id,
            $updated->customer_id,
            $updated->worker_id,
            (string) $updated->status?->value,
            $this->activeSecurityCodeExpiresAt($updated)?->toIso8601String(),
        ));
        $this->dispatchTrackingUpdate($updated);
        $this->lifecycleNotifications->notifyCustomer(
            booking: $updated,
            canonicalType: 'cleaning.booking.worker_arrived',
            action: 'worker_arrived',
            actorRole: 'worker',
            fromStatus: $fromStatus,
            occurredAt: $updated->arrived_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    public function startWork(CleaningBooking $booking): CleaningBooking
    {
        $updated = DB::transaction(function () use ($booking): CleaningBooking {
            $worker = $this->currentWorker();
            $lockedBooking = $this->lockBooking($booking);

            if (in_array($lockedBooking->status, [
                CleaningBookingStatus::AwaitingStartVerification,
                CleaningBookingStatus::AwaitingWorkerStartConfirmation,
            ], true)) {
                $securityCode = DB::table('booking_security_codes')
                    ->where('booking_id', $lockedBooking->id)
                    ->where('booking_type', $lockedBooking->getMorphClass())
                    ->orderByDesc('id')
                    ->first();

                if (! $securityCode || $securityCode->consumed_at === null) {
                    throw new InvalidArgumentException('Customer must verify the security code before work can start.');
                }

                $assignment = $this->activeAssignmentForWorker($lockedBooking->id, $worker->id, true);

                if (! $assignment instanceof CleaningBookingWorkerAssignment && (int) $lockedBooking->worker_id !== (int) $worker->id) {
                    throw new InvalidArgumentException('Worker must accept the booking before approving start.');
                }

                if ($assignment?->status === CleaningBookingWorkerAssignmentStatus::StartApproved) {
                    throw new InvalidArgumentException('Worker has already approved the booking start.');
                }

                if ($assignment instanceof CleaningBookingWorkerAssignment) {
                    $assignment->forceFill([
                        'status' => CleaningBookingWorkerAssignmentStatus::StartApproved,
                        'start_approved_at' => $assignment->start_approved_at ?? now(),
                    ])->save();
                }

                $startApproved = CleaningBookingWorkerAssignment::query()
                    ->where('cleaning_booking_id', $lockedBooking->id)
                    ->whereNotNull('start_approved_at')
                    ->lockForUpdate()
                    ->count();

                $required = $this->requiredWorkers($lockedBooking);

                if ($assignment === null || $startApproved >= $required) {
                    $startedAt = $lockedBooking->work_started_at ?? now();

                    CleaningBookingWorkerAssignment::query()
                        ->where('cleaning_booking_id', $lockedBooking->id)
                        ->whereNotNull('start_approved_at')
                        ->whereIn('status', [
                            CleaningBookingWorkerAssignmentStatus::StartApproved->value,
                            CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
                        ])
                        ->update([
                            'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
                            'work_started_at' => $startedAt,
                            'updated_at' => now(),
                        ]);

                    $lockedBooking->update([
                        'status' => CleaningBookingStatus::InProgress,
                        'work_started_at' => $startedAt,
                    ]);
                }

                return $this->freshBooking($lockedBooking);
            }

            if ($lockedBooking->status !== CleaningBookingStatus::WorkerAssigned) {
                throw new InvalidArgumentException('Booking must be assigned to start work.');
            }

            $assignment = $this->activeAssignmentForWorker($lockedBooking->id, $worker->id, true);
            $startedAt = now();

            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                    'start_approved_at' => $assignment->start_approved_at ?? $startedAt,
                    'work_started_at' => $assignment->work_started_at ?? $startedAt,
                ])->save();
            } elseif ((int) $lockedBooking->worker_id !== (int) $worker->id) {
                throw new InvalidArgumentException('Worker must accept the booking before starting work.');
            }

            $lockedBooking->update([
                'status' => CleaningBookingStatus::InProgress,
                'work_started_at' => $startedAt,
            ]);

            return $this->freshBooking($lockedBooking);
        });

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    public function complete(CleaningBooking $booking, ?string $completionMessage = null): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;
        $completionMessage = is_string($completionMessage) && mb_trim($completionMessage) !== ''
            ? mb_trim($completionMessage)
            : null;

        $becameAwaitingCustomerCompletion = false;

        $updated = DB::transaction(function () use ($booking, $completionMessage, &$becameAwaitingCustomerCompletion): CleaningBooking {
            $worker = $this->currentWorker();
            $lockedBooking = $this->lockBooking($booking);

            if ($lockedBooking->status !== CleaningBookingStatus::InProgress) {
                throw new InvalidArgumentException('Booking must be in progress to mark completion.');
            }

            $assignment = $this->activeAssignmentForWorker($lockedBooking->id, $worker->id, true);

            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                if (! in_array((string) ($assignment->status?->value ?? $assignment->status), [
                    CleaningBookingWorkerAssignmentStatus::InProgress->value,
                    CleaningBookingWorkerAssignmentStatus::StartApproved->value,
                ], true)) {
                    throw new InvalidArgumentException('Worker assignment must be in progress to mark completion.');
                }

                $finishedAt = now();
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
                    'work_finished_at' => $finishedAt,
                    'worker_completion_message' => $completionMessage,
                ])->save();

                $finishedAssignments = CleaningBookingWorkerAssignment::query()
                    ->where('cleaning_booking_id', $lockedBooking->id)
                    ->whereIn('status', [
                        CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
                        CleaningBookingWorkerAssignmentStatus::Completed->value,
                    ])
                    ->lockForUpdate()
                    ->count();

                if ($finishedAssignments >= $this->requiredWorkers($lockedBooking)) {
                    $lockedBooking->update([
                        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
                        'work_finished_at' => $finishedAt,
                        'worker_completion_message' => $completionMessage,
                        'customer_completion_rejection_message' => null,
                        'completion_rejected_at' => null,
                    ]);
                    $becameAwaitingCustomerCompletion = true;
                }

                return $this->freshBooking($lockedBooking);
            }

            if ((int) $lockedBooking->worker_id !== (int) $worker->id) {
                throw new InvalidArgumentException('Worker must accept the booking before marking completion.');
            }

            $lockedBooking->update([
                'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
                'work_finished_at' => now(),
                'worker_completion_message' => $completionMessage,
                'customer_completion_rejection_message' => null,
                'completion_rejected_at' => null,
            ]);
            $becameAwaitingCustomerCompletion = true;

            return $this->freshBooking($lockedBooking);
        });

        if ($becameAwaitingCustomerCompletion) {
            $expiresAt = now()->addMinutes(30)->toIso8601String();

            BroadcastAfterResponse::send(new CleaningOrderAwaitingCustomerCompletion(
                $updated->id,
                $updated->worker_id,
                (string) $updated->status?->value,
                $expiresAt,
                $updated->worker_completion_message,
            ));
            $this->lifecycleNotifications->notifyCustomer(
                booking: $updated,
                canonicalType: 'cleaning.booking.completion_requested',
                action: 'completion_requested',
                actorRole: 'worker',
                fromStatus: $fromStatus,
                occurredAt: $updated->work_finished_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
                extraData: [
                    'completionMessage' => $updated->worker_completion_message,
                    'expiresAt' => $expiresAt,
                    'requiresCustomerAction' => true,
                ],
                templateContext: [
                    'completion_message' => $updated->worker_completion_message,
                ],
            );
        }

        $this->dispatchTrackingUpdate($updated);

        return $updated;
    }

    public function finish(CleaningBooking $booking, string $finishType, ?string $reasonType = null, ?string $reasonNote = null): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        if (! in_array($finishType, [self::FINISH_TYPE_SUCCESS, self::FINISH_TYPE_DISPUTE], true)) {
            throw new InvalidArgumentException('Invalid finish type.');
        }

        $updated = DB::transaction(function () use ($booking, $finishType, $reasonType, $reasonNote): CleaningBooking {
            $worker = Auth::user()?->worker;
            if (! $worker instanceof Worker) {
                throw new InvalidArgumentException('User must have an associated worker.');
            }

            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($lockedBooking->status !== CleaningBookingStatus::InProgress) {
                throw new InvalidArgumentException('Booking must be in progress to finish.');
            }

            if ($finishType === self::FINISH_TYPE_SUCCESS) {
                $lockedBooking->update([
                    'status' => CleaningBookingStatus::Completed,
                    'work_finished_at' => now(),
                    'customer_confirmed_at' => now(),
                    'worker_completion_message' => null,
                    'customer_completion_rejection_message' => null,
                    'completion_rejected_at' => null,
                ]);

                $freshBooking = $lockedBooking->fresh(['customer', 'worker.user', 'workerAssignments.worker', 'disputes']);

                if ($freshBooking instanceof CleaningBooking) {
                    $this->finalizeWorkerAdminFees($freshBooking);

                    return $freshBooking;
                }

                return $lockedBooking;
            }

            $note = is_string($reasonNote) && mb_trim($reasonNote) !== '' ? mb_trim($reasonNote) : null;
            $category = $this->normalizeFinishCategory($reasonType);
            $description = trim((string) $category.($note !== null ? PHP_EOL.PHP_EOL.$note : ''));

            $lockedBooking->update([
                'status' => CleaningBookingStatus::UnderDispute,
                'work_finished_at' => now(),
                'worker_completion_message' => $description,
                'customer_completion_rejection_message' => null,
                'completion_rejected_at' => null,
            ]);

            $dispute = Dispute::query()->create([
                'booking_id' => $lockedBooking->id,
                'booking_type' => $lockedBooking->getMorphClass(),
                'ticket_number' => $this->generateDisputeTicketNumber($lockedBooking),
                'description' => $description,
                'category' => $category,
                'status' => DisputeStatus::Open,
                'resolution' => null,
                'worker_earnings_frozen' => true,
            ]);

            SystemAlert::query()->create([
                'booking_id' => $lockedBooking->id,
                'booking_type' => $lockedBooking->getMorphClass(),
                'alert_type' => AlertType::CleaningBookingDispute,
                'severity' => AlertSeverity::Critical,
                'status' => SystemAlertStatus::New,
                'payload' => [
                    'source' => 'cleaning_worker_finish_review',
                    'booking_id' => $lockedBooking->id,
                    'booking_number' => $lockedBooking->booking_number,
                    'worker_id' => $worker->id,
                    'customer_id' => $lockedBooking->customer_id,
                    'dispute_id' => $dispute->id,
                    'ticket_number' => $dispute->ticket_number,
                    'reason_type' => $category,
                    'reason_note' => $note,
                    'message' => self::MANUAL_REVIEW_MESSAGE,
                ],
            ]);

            return $lockedBooking->fresh(['customer', 'worker.user', 'workerAssignments.worker.user', 'disputes']);
        });

        $this->dispatchTrackingUpdate($updated);

        $this->lifecycleNotifications->notifyCustomer(
            booking: $updated,
            canonicalType: $updated->status === CleaningBookingStatus::Completed ? 'cleaning.booking.completed' : 'cleaning.booking.under_dispute',
            action: $updated->status === CleaningBookingStatus::Completed ? 'worker_finished_successfully' : 'worker_requested_admin_review',
            actorRole: 'worker',
            fromStatus: $fromStatus,
            occurredAt: $updated->work_finished_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
            extraData: [
                'isTimerRunning' => false,
                'timerStoppedAt' => $updated->work_finished_at?->toIso8601String(),
                'suspendedMessage' => $updated->status === CleaningBookingStatus::UnderDispute ? self::MANUAL_REVIEW_MESSAGE : null,
            ],
        );

        return $updated;
    }

    public function cancel(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        $updated = DB::transaction(function () use ($booking, $reason) {
            $worker = Auth::user()?->worker;
            if (! $worker) {
                throw new InvalidArgumentException('User must have an associated worker.');
            }

            $allowedStatuses = [
                CleaningBookingStatus::WorkerAssigned,
                CleaningBookingStatus::InProgress,
            ];

            if (! in_array($booking->status, $allowedStatuses, true)) {
                throw new InvalidArgumentException('Booking cannot be cancelled in current status.');
            }

            $booking->update([
                'status' => CleaningBookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->workerTrustService->applyBookingCancellationPenalty($worker, $booking);

            return $booking->fresh();
        });

        $this->dispatchTrackingUpdate($updated);
        $this->lifecycleNotifications->notifyCustomer(
            booking: $updated,
            canonicalType: 'cleaning.booking.order_cancelled',
            action: 'worker_cancelled',
            actorRole: 'worker',
            fromStatus: $fromStatus,
            occurredAt: $updated->cancelled_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        $status = $booking->status instanceof CleaningBookingStatus ? $booking->status->value : (string) $booking->status;

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $status,
            'statusLabel' => $booking->status instanceof CleaningBookingStatus ? $booking->status->label() : null,
            'workerId' => $booking->worker_id,
            'assignmentMode' => $booking->resolvedAssignmentMode(),
            'requiredWorkers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            'acceptedWorkers' => $booking->acceptedWorkerCount(),
            'remainingWorkers' => $booking->remainingWorkerCount(),
            'startApprovedWorkers' => $booking->startApprovedWorkerCount(),
            'notStartApprovedWorkers' => $booking->notStartApprovedWorkerCount(),
            'isTeamFulfilled' => $booking->isTeamFulfilled(),
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'timerStoppedAt' => $booking->work_finished_at?->toIso8601String(),
            'isTimerRunning' => $status === CleaningBookingStatus::InProgress->value,
            'suspendedMessage' => $status === CleaningBookingStatus::UnderDispute->value ? self::MANUAL_REVIEW_MESSAGE : null,
            'workerCompletionMessage' => $booking->worker_completion_message,
            'customerCompletionRejectionMessage' => $booking->customer_completion_rejection_message,
            'completionRejectedAt' => $booking->completion_rejected_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }

    private function finalizeWorkerAdminFees(CleaningBooking $booking): void
    {
        $booking->loadMissing('workerAssignments.worker');

        foreach ($booking->workerAssignments as $assignment) {
            $status = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus ? $assignment->status->value : (string) $assignment->status;

            if (! in_array($status, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
                continue;
            }

            $worker = $assignment->worker;
            $adminFee = (float) $assignment->admin_margin_amount;

            if ($worker instanceof Worker && $adminFee > 0) {
                $this->depositService->recordAdminFeeDebit($worker, $booking, $adminFee);
            }
        }
    }

    private function normalizeFinishCategory(?string $category): string
    {
        $category = is_string($category) && $category !== '' ? $category : DisputeCategory::Other->value;

        return in_array($category, [
            DisputeCategory::CustomerTermsViolation->value,
            DisputeCategory::FinancialOrVerbalDispute->value,
            DisputeCategory::ForceMajeure->value,
            DisputeCategory::Other->value,
        ], true) ? $category : DisputeCategory::Other->value;
    }

    private function generateDisputeTicketNumber(CleaningBooking $booking): string
    {
        return sprintf('CLN-DSP-%s-%s', $booking->id, now()->format('YmdHis'));
    }

    private function activeSecurityCodeExpiresAt(CleaningBooking $booking): ?\Carbon\CarbonInterface
    {
        $record = DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass())
            ->orderByDesc('id')
            ->first();

        if (! $record || $record->expires_at === null) {
            return null;
        }

        return Carbon::parse($record->expires_at);
    }

    private function securityCodeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function currentWorker(): Worker
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            throw new InvalidArgumentException('User must have an associated worker.');
        }

        return $worker;
    }

    private function lockBooking(CleaningBooking $booking): CleaningBooking
    {
        return CleaningBooking::query()
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function activeAssignmentForWorker(int $bookingId, int $workerId, bool $lock = false): ?CleaningBookingWorkerAssignment
    {
        $query = CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $bookingId)
            ->where('worker_id', $workerId)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function requiredWorkers(CleaningBooking $booking): int
    {
        return max(1, (int) ($booking->number_of_workers ?? 1));
    }

    private function freshBooking(CleaningBooking $booking): CleaningBooking
    {
        return $booking->fresh([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]);
    }
}
