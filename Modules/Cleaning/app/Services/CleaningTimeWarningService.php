<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Support\Broadcast\BroadcastAfterResponse;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Models\EventBooking;

final class CleaningTimeWarningService
{
    public function __construct(
        private readonly CleaningBookingWorkerCompletionService $workerCompletionService,
        private readonly CleaningBookingSessionLifecycleService $sessionLifecycle,
        private readonly CleaningTimeWarningWorkerNotificationService $workerNotificationService,
        private readonly CleaningCouponPricingService $couponPricingService,
    ) {}

    public function accept(CleaningTimeWarning $warning, ?int $additionalMinutes = null): CleaningTimeWarning
    {
        $alreadyResolved = false;
        $fromStatus = null;

        $warning = DB::transaction(function () use ($warning, $additionalMinutes, &$alreadyResolved, &$fromStatus): CleaningTimeWarning {
            $warning = CleaningTimeWarning::query()->lockForUpdate()->findOrFail($warning->id);

            if ($warning->worker_responded_at !== null) {
                if ($warning->worker_response !== CleaningTimeWarningResponse::ExtendTime) {
                    throw new InvalidArgumentException('Extension request has already been resolved with a different decision.');
                }

                $alreadyResolved = true;

                return $warning->fresh(['booking']);
            }

            $booking = $this->lockedCleaningBooking($warning);
            $this->assertCleaningDecisionAllowed($booking, $warning);
            $fromStatus = $this->bookingStatus($booking);
            $quotedAmount = round((float) ($warning->quoted_amount ?? 0), 2);
            $quotedAdminMargin = round((float) ($warning->quoted_admin_margin_amount ?? 0), 2);
            $quotedBaseAmount = $warning->quoted_base_amount !== null
                ? round((float) $warning->quoted_base_amount, 2)
                : max(0.0, round($quotedAmount - $quotedAdminMargin, 2));
            $quotedServiceAmount = $quotedAmount > 0 ? $quotedAmount : $quotedBaseAmount;
            $shouldApplyPrice = $warning->price_applied_at === null && $quotedAmount > 0;

            if ($shouldApplyPrice) {
                $booking->forceFill([
                    'extension_fee_total' => round((float) ($booking->extension_fee_total ?? 0) + $quotedAmount, 2),
                    'admin_margin_amount' => round((float) ($booking->admin_margin_amount ?? 0) + $quotedAdminMargin, 2),
                    'total_price' => round((float) ($booking->total_price ?? 0) + $quotedAmount, 2),
                ]);
                $this->couponPricingService->saveAfterApprovedExtension($booking);
            }

            $warning->update([
                'worker_response' => CleaningTimeWarningResponse::ExtendTime,
                'worker_responded_at' => now(),
                'additional_minutes' => $warning->additional_minutes ?? $additionalMinutes,
                'price_applied_at' => $warning->price_applied_at ?? now(),
            ]);

            $session = $this->resolveWarningSession($warning, $booking);
            if ($session instanceof CleaningBookingSession) {
                $sessionAssignment = $this->warningSessionAssignment(
                    $warning,
                    $session,
                );

                if (! $sessionAssignment instanceof CleaningBookingSessionWorkerAssignment) {
                    throw new InvalidArgumentException(
                        'تعذر ربط طلب التمديد بالعامل في هذه الجلسة. حدّث الطلب وحاول مرة أخرى.',
                    );
                }

                if ($shouldApplyPrice) {
                    $session->forceFill([
                        'extension_fee_total' => round(
                            (float) ($session->extension_fee_total ?? 0) + $quotedAmount,
                            2,
                        ),
                        'admin_margin_amount' => round(
                            (float) ($session->admin_margin_amount ?? 0) + $quotedAdminMargin,
                            2,
                        ),
                        'total_price' => round(
                            (float) ($session->total_price ?? 0) + $quotedAmount,
                            2,
                        ),
                    ]);

                    $serviceShareAmount = round(
                        (float) ($sessionAssignment->service_share_amount ?? 0) + $quotedServiceAmount,
                        2,
                    );
                    $adminMarginAmount = round(
                        (float) ($sessionAssignment->admin_margin_amount ?? 0) + $quotedAdminMargin,
                        2,
                    );
                    $travelFee = round((float) ($sessionAssignment->travel_fee ?? 0), 2);

                    $sessionAssignment->forceFill([
                        'service_share_amount' => $serviceShareAmount,
                        'admin_margin_amount' => $adminMarginAmount,
                        'worker_amount' => max(
                            0.0,
                            round($serviceShareAmount + $travelFee, 2),
                        ),
                    ]);
                }

                $sessionAssignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                    'work_finished_at' => null,
                    'worker_completion_message' => null,
                ])->save();

                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::InProgress,
                    'work_finished_at' => null,
                    'payment_status' => 'pending',
                    'payment_settled_at' => null,
                ])->save();

                $booking->forceFill([
                    'work_finished_at' => null,
                ])->saveQuietly();

                $this->sessionLifecycle->syncParentStatus($booking);

                return $warning->fresh(['booking']);
            }

            $assignment = $this->warningAssignment($warning, $booking);
            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                if ($shouldApplyPrice) {
                    $serviceShareAmount = round((float) ($assignment->service_share_amount ?? 0) + $quotedServiceAmount, 2);
                    $adminMarginAmount = round((float) ($assignment->admin_margin_amount ?? 0) + $quotedAdminMargin, 2);
                    $travelFee = round((float) ($assignment->travel_fee ?? 0), 2);

                    $assignment->forceFill([
                        'service_share_amount' => $serviceShareAmount,
                        'admin_margin_amount' => $adminMarginAmount,
                        'worker_amount' => max(0.0, round($serviceShareAmount + $travelFee, 2)),
                    ]);
                }

                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
                    'work_finished_at' => null,
                    'worker_completion_message' => null,
                    'worker_finished_cleaning_services' => null,
                    'worker_finished_property_rooms' => null,
                ])->save();

                $booking->forceFill([
                    'status' => $this->workerCompletionService->resolveBookingStatus($booking),
                    'work_finished_at' => null,
                ])->save();
            } else {
                $booking->forceFill([
                    'status' => CleaningBookingStatus::InProgress,
                    'work_finished_at' => null,
                ])->save();
            }

            return $warning->fresh(['booking']);
        });

        if (! $alreadyResolved) {
            $this->broadcastDecision($warning, 'extension_accepted');
            DB::afterCommit(fn () => $this->workerNotificationService->accepted($warning, $fromStatus));
        }

        return $warning;
    }

    public function reject(CleaningTimeWarning $warning, ?string $message = null): CleaningTimeWarning
    {
        $alreadyResolved = false;
        $fromStatus = null;

        $warning = DB::transaction(function () use ($warning, $message, &$alreadyResolved, &$fromStatus): CleaningTimeWarning {
            $warning = CleaningTimeWarning::query()->lockForUpdate()->findOrFail($warning->id);

            if ($warning->worker_responded_at !== null) {
                if ($warning->worker_response !== CleaningTimeWarningResponse::CommitCurrentTime) {
                    throw new InvalidArgumentException('Extension request has already been resolved with a different decision.');
                }

                $alreadyResolved = true;

                return $warning->fresh(['booking']);
            }

            $booking = $this->lockedRejectBooking($warning);
            if ($booking instanceof CleaningBooking) {
                $this->assertCleaningDecisionAllowed($booking, $warning);
            } elseif ($booking->status !== EventBookingStatus::InProgress) {
                // Preserve legacy event warning behavior for in-progress work,
                // but never finish an event that has not started or has ended.
                throw new InvalidArgumentException('Event booking cannot resolve an extension in its current state.');
            }
            $fromStatus = $this->bookingStatus($booking);

            $warning->update([
                'worker_response' => CleaningTimeWarningResponse::CommitCurrentTime,
                'worker_responded_at' => now(),
                'worker_reject_message' => $message,
            ]);

            if ($booking instanceof EventBooking) {
                if ($booking->status !== EventBookingStatus::Cancelled) {
                    $booking->forceFill([
                        'status' => EventBookingStatus::Completed,
                    ])->save();
                }

                return $warning->fresh(['booking']);
            }

            $session = $this->resolveWarningSession($warning, $booking);
            if ($session instanceof CleaningBookingSession) {
                $sessionAssignment = $this->warningSessionAssignment(
                    $warning,
                    $session,
                );

                if (! $sessionAssignment instanceof CleaningBookingSessionWorkerAssignment) {
                    throw new InvalidArgumentException(
                        'تعذر ربط طلب التمديد بالعامل في هذه الجلسة. حدّث الطلب وحاول مرة أخرى.',
                    );
                }

                $sessionAssignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
                ])->save();

                $session->forceFill([
                    'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion,
                    'payment_status' => 'ready',
                ])->save();

                $this->sessionLifecycle->syncParentStatus($booking);

                return $warning->fresh(['booking']);
            }

            $assignment = $this->warningAssignment($warning, $booking);
            if ($assignment instanceof CleaningBookingWorkerAssignment) {
                $assignment->forceFill([
                    'status' => CleaningBookingWorkerAssignmentStatus::Completed,
                ])->save();

                $status = $this->workerCompletionService->resolveBookingStatus($booking);
                $booking->forceFill([
                    'status' => $status,
                    'work_finished_at' => $status === CleaningBookingStatus::Completed
                        ? ($booking->work_finished_at ?? now())
                        : null,
                    'customer_confirmed_at' => $status === CleaningBookingStatus::Completed
                        ? ($booking->customer_confirmed_at ?? now())
                        : $booking->customer_confirmed_at,
                ])->save();
            } else {
                $booking->forceFill([
                    'status' => CleaningBookingStatus::Completed,
                    'work_finished_at' => $booking->work_finished_at ?? now(),
                    'customer_confirmed_at' => $booking->customer_confirmed_at ?? now(),
                ])->save();
            }

            return $warning->fresh(['booking']);
        });

        if (! $alreadyResolved) {
            $this->broadcastDecision($warning, 'extension_rejected', $message);
            DB::afterCommit(fn () => $this->workerNotificationService->declined(
                $warning,
                $fromStatus,
                $warning->worker_reject_message,
            ));
        }

        return $warning;
    }

    private function lockedCleaningBooking(CleaningTimeWarning $warning): CleaningBooking
    {
        $booking = $warning->booking;

        if (! $booking instanceof CleaningBooking) {
            throw new InvalidArgumentException('Extension request booking is invalid.');
        }

        return CleaningBooking::query()->lockForUpdate()->findOrFail($booking->id);
    }

    private function lockedRejectBooking(CleaningTimeWarning $warning): CleaningBooking|EventBooking
    {
        $booking = $warning->booking;

        if ($booking instanceof CleaningBooking) {
            return CleaningBooking::query()->lockForUpdate()->findOrFail($booking->id);
        }

        if ($booking instanceof EventBooking) {
            return EventBooking::query()->lockForUpdate()->findOrFail($booking->id);
        }

        throw new InvalidArgumentException('Extension request booking is invalid.');
    }

    private function resolveWarningSession(
        CleaningTimeWarning $warning,
        CleaningBooking $booking,
    ): ?CleaningBookingSession {
        if ($warning->cleaning_booking_session_id !== null) {
            return $this->lockedWarningSession($warning, $booking);
        }

        if ($warning->worker_id === null || ! $booking->sessions()->exists()) {
            return null;
        }

        $priorityStatuses = [
            CleaningBookingSessionStatus::TimeExtensionRequested->value,
            CleaningBookingSessionStatus::AwaitingCustomerCompletion->value,
        ];

        foreach ($priorityStatuses as $status) {
            $sessions = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('status', $status)
                ->whereHas('workerAssignments', function ($query) use ($warning): void {
                    $query
                        ->where('worker_id', $warning->worker_id)
                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                })
                ->lockForUpdate()
                ->get();

            if ($sessions->count() === 1) {
                /** @var CleaningBookingSession $session */
                $session = $sessions->first();

                $warning->forceFill([
                    'cleaning_booking_session_id' => $session->id,
                ])->save();

                return $session;
            }

            if ($sessions->count() > 1) {
                throw new InvalidArgumentException(
                    'تعذر تحديد الجلسة المرتبطة بطلب التمديد. حدّث الطلب وحاول مرة أخرى.',
                );
            }
        }

        return null;
    }

    private function lockedWarningSession(
        CleaningTimeWarning $warning,
        CleaningBooking $booking,
    ): CleaningBookingSession {
        $sessionId = $warning->cleaning_booking_session_id;

        if ($sessionId === null) {
            throw new InvalidArgumentException('جلسة طلب التمديد غير محددة.');
        }

        $session = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->find((int) $sessionId);

        if (! $session instanceof CleaningBookingSession) {
            throw new InvalidArgumentException(
                'جلسة طلب التمديد لا تتبع لهذا الطلب.',
            );
        }

        return $session;
    }

    private function warningSessionAssignment(
        CleaningTimeWarning $warning,
        CleaningBookingSession $session,
    ): ?CleaningBookingSessionWorkerAssignment {
        if ($warning->worker_id === null) {
            return null;
        }

        return CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_session_id', $session->id)
            ->where('worker_id', $warning->worker_id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->lockForUpdate()
            ->first();
    }

    /** Require customer consent and an active booking after locking, before writes. */
    private function assertCleaningDecisionAllowed(CleaningBooking $booking, CleaningTimeWarning $warning): void
    {
        if (! in_array($booking->status, [
            CleaningBookingStatus::InProgress,
            CleaningBookingStatus::AwaitingCustomerCompletion,
            CleaningBookingStatus::TimeExtensionRequested,
        ], true)) {
            throw new InvalidArgumentException('Cleaning booking cannot accept an extension decision in its current state.');
        }

        if (
            $warning->customer_response !== CleaningTimeWarningResponse::ExtendTime
            || $warning->customer_responded_at === null
        ) {
            throw new InvalidArgumentException('The customer must approve an extension before the worker decides.');
        }

        // Controller ownership is checked before this transaction. Revalidate
        // an explicitly targeted worker after the booking lock so a withdrawn
        // or replaced assignment cannot approve an old signed request.
        if ($warning->worker_id !== null) {
            $workerId = (int) $warning->worker_id;
            $isLegacyWorker = (int) ($booking->worker_id ?? 0) === $workerId;
            $hasCommittedAssignment = CleaningBookingWorkerAssignment::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('worker_id', $workerId)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                ->lockForUpdate()
                ->exists();

            if (! $isLegacyWorker && ! $hasCommittedAssignment) {
                throw new InvalidArgumentException('The extension request worker assignment is no longer active.');
            }
        }
    }

    private function warningAssignment(CleaningTimeWarning $warning, CleaningBooking $booking): ?CleaningBookingWorkerAssignment
    {
        if ($warning->worker_id === null) {
            return null;
        }

        return CleaningBookingWorkerAssignment::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('worker_id', $warning->worker_id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->lockForUpdate()
            ->first();
    }

    private function broadcastDecision(CleaningTimeWarning $warning, string $decision, ?string $message = null): void
    {
        $booking = $warning->relationLoaded('booking') ? $warning->booking : $warning->booking()->first();

        if (! $booking instanceof CleaningBooking) {
            return;
        }

        $sessionId = $warning->cleaning_booking_session_id !== null
            ? (int) $warning->cleaning_booking_session_id
            : null;
        $session = $sessionId !== null
            ? CleaningBookingSession::query()->find($sessionId)
            : null;
        $sessionStatus = $session instanceof CleaningBookingSession
            ? ($session->status?->value ?? (string) $session->status)
            : null;
        $status = $booking->status?->value ?? (string) $booking->status;
        $occurredAt = now()->toIso8601String();
        $workerId = $warning->worker_id ?? $booking->worker_id;

        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'bookingId' => $booking->id,
            'status' => $status,
            'sessionId' => $sessionId,
            'sessionStatus' => $sessionStatus,
            'workerId' => $workerId,
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'warningId' => $warning->id,
            'decision' => $decision,
            'updatedAt' => $occurredAt,
        ]));

        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $booking->id,
            $workerId,
            $decision,
            $message,
            $occurredAt,
            $status,
            $warning->id,
            $sessionId,
        ));
    }

    private function bookingStatus(CleaningBooking|EventBooking $booking): ?string
    {
        $status = $booking->status;

        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        return $status !== null ? (string) $status : null;
    }
}
