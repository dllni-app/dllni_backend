<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Support\Broadcast\BroadcastAfterResponse;
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
    ) {}

    public function accept(CleaningTimeWarning $warning, ?int $additionalMinutes = null): CleaningTimeWarning
    {
        $alreadyResolved = false;

        $warning = DB::transaction(function () use ($warning, $additionalMinutes, &$alreadyResolved): CleaningTimeWarning {
            $warning = CleaningTimeWarning::query()->lockForUpdate()->findOrFail($warning->id);

            if ($warning->worker_responded_at !== null) {
                $alreadyResolved = true;

                return $warning->fresh(['booking']);
            }

            $booking = $this->lockedCleaningBooking($warning);
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
                ])->save();
            }

            $warning->update([
                'worker_response' => CleaningTimeWarningResponse::ExtendTime,
                'worker_responded_at' => now(),
                'additional_minutes' => $warning->additional_minutes ?? $additionalMinutes,
                'price_applied_at' => $warning->price_applied_at ?? now(),
            ]);

            if ($warning->cleaning_booking_session_id !== null) {
                $session = $this->lockedWarningSession($warning, $booking);
                $sessionAssignment = $this->warningSessionAssignment(
                    $warning,
                    $session,
                );

                if (! $sessionAssignment instanceof CleaningBookingSessionWorkerAssignment) {
                    throw new InvalidArgumentException(
                        'Extension request worker assignment is invalid for this session.',
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
        }

        return $warning;
    }

    public function reject(CleaningTimeWarning $warning, ?string $message = null): CleaningTimeWarning
    {
        $alreadyResolved = false;

        $warning = DB::transaction(function () use ($warning, $message, &$alreadyResolved): CleaningTimeWarning {
            $warning = CleaningTimeWarning::query()->lockForUpdate()->findOrFail($warning->id);

            if ($warning->worker_responded_at !== null) {
                $alreadyResolved = true;

                return $warning->fresh(['booking']);
            }

            $booking = $this->lockedRejectBooking($warning);

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

            if ($warning->cleaning_booking_session_id !== null) {
                $session = $this->lockedWarningSession($warning, $booking);
                $sessionAssignment = $this->warningSessionAssignment(
                    $warning,
                    $session,
                );

                if (! $sessionAssignment instanceof CleaningBookingSessionWorkerAssignment) {
                    throw new InvalidArgumentException(
                        'Extension request worker assignment is invalid for this session.',
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

    private function lockedWarningSession(
        CleaningTimeWarning $warning,
        CleaningBooking $booking,
    ): CleaningBookingSession {
        $sessionId = $warning->cleaning_booking_session_id;

        if ($sessionId === null) {
            throw new InvalidArgumentException('Extension request session is missing.');
        }

        $session = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->lockForUpdate()
            ->find((int) $sessionId);

        if (! $session instanceof CleaningBookingSession) {
            throw new InvalidArgumentException(
                'Extension request session is invalid for this booking.',
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

}
