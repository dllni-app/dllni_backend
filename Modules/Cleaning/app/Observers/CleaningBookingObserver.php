<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\User;
use App\Models\BookingStatusLog;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use BackedEnum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\DepositService;
use Throwable;

final class CleaningBookingObserver
{
    public function created(CleaningBooking $booking): void
    {
        BookingStatusLog::create([
            'booking_id' => $booking->id,
            'booking_type' => CleaningBooking::class,
            'from_status' => null,
            'to_status' => $booking->status->value,
        ]);

        if ($booking->status !== CleaningBookingStatus::Pending) {
            $this->notifyLifecycleCreated($booking);

            return;
        }

        NotifyEligibleWorkersNewOrderJob::dispatch($booking->id)->afterCommit();
        $this->notifyLifecycleCreated($booking);
    }

    public function updated(CleaningBooking $booking): void
    {
        if (! $booking->wasChanged()) {
            return;
        }

        $changes = $booking->getChanges();
        unset($changes['updated_at']);
        if ($changes === []) {
            return;
        }

        $fromStatus = $booking->getOriginal('status');
        $fromStatusValue = $fromStatus instanceof BackedEnum ? $fromStatus->value : (string) $fromStatus;

        if ($booking->wasChanged('status')) {
            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'booking_type' => CleaningBooking::class,
                'from_status' => $fromStatusValue,
                'to_status' => $booking->status->value,
            ]);

            if ($booking->status === CleaningBookingStatus::Completed) {
                $this->chargeAdminCommission($booking);
            }

            return;
        }

        $this->notifyLifecycleUpdated($booking, $fromStatusValue);
    }

    /**
     * Charge the admin commission as a deposit liability for every worker who
     * completed this booking. Uses the precomputed admin margin per accepted
     * assignment, falling back to the legacy single-worker booking margin.
     * Idempotent per (worker, booking) via DepositService guards.
     */
    private function chargeAdminCommission(CleaningBooking $booking): void
    {
        try {
            $depositService = app(DepositService::class);

            $assignments = $booking->acceptedWorkerAssignments()
                ->with('worker.deposit')
                ->get();

            if ($assignments->isNotEmpty()) {
                $assignments->each(function (CleaningBookingWorkerAssignment $assignment) use ($depositService, $booking): void {
                    $worker = $assignment->worker;
                    $amount = (float) ($assignment->admin_margin_amount ?? 0);

                    if ($worker instanceof Worker && $amount > 0) {
                        $depositService->recordAdminFeeDebit($worker, $booking, $amount);
                    }
                });

                return;
            }

            // Legacy single-worker booking without team assignments.
            $worker = $booking->worker;
            $amount = (float) ($booking->admin_margin_amount ?? 0);

            if ($worker instanceof Worker && $amount > 0) {
                $depositService->recordAdminFeeDebit($worker, $booking, $amount);
            }
        } catch (Throwable $exception) {
            // Never block the booking lifecycle on a commission-charge failure.
            report($exception);
        }
    }

    private function notifyLifecycleCreated(CleaningBooking $booking): void
    {
        $this->notifyBothParties(
            booking: $booking,
            canonicalType: 'cleaning.booking.created',
            fromStatus: null,
        );
    }

    private function notifyLifecycleUpdated(CleaningBooking $booking, ?string $fromStatus): void
    {
        $this->notifyBothParties(
            booking: $booking,
            canonicalType: 'cleaning.booking.updated',
            fromStatus: $fromStatus,
        );
    }

    private function notifyBothParties(CleaningBooking $booking, string $canonicalType, ?string $fromStatus): void
    {
        $customer = $booking->customer;
        $workerUser = $booking->worker?->user;

        if ($customer instanceof User) {
            $customer->notify(new BookingLifecycleNotification(
                booking: $booking,
                canonicalType: $canonicalType,
                actorRole: 'worker',
                targetRole: 'customer',
                fromStatus: $fromStatus,
            ));
        }

        if ($workerUser instanceof User) {
            $workerUser->notify(new BookingLifecycleNotification(
                booking: $booking,
                canonicalType: $canonicalType,
                actorRole: 'customer',
                targetRole: 'worker',
                fromStatus: $fromStatus,
            ));
        }
    }
}
