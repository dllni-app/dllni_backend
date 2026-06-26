<?php

declare(strict_types=1);

namespace Modules\Cleaning\Observers;

use App\Enums\GenderPreference;
use App\Jobs\ConvertPreferredCleaningBookingToOpenJob;
use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\BookingStatusLog;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use BackedEnum;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\DepositService;
use Modules\User\Services\FemaleWorkerSafetyPolicyService;
use Throwable;

final class CleaningBookingObserver
{
    public function creating(CleaningBooking $booking): void
    {
        $this->applyWorkEnvironmentSnapshot($booking);
    }

    public function updating(CleaningBooking $booking): void
    {
        if ($booking->isDirty('gender_preference')) {
            $this->applyWorkEnvironmentSnapshot($booking);
        }
    }

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
        $this->dispatchPreferredWorkerFallbackIfNeeded($booking);
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

    private function applyWorkEnvironmentSnapshot(CleaningBooking $booking): void
    {
        $genderPreference = $booking->gender_preference instanceof GenderPreference
            ? $booking->gender_preference->value
            : (string) $booking->gender_preference;

        if ($genderPreference !== GenderPreference::Female->value) {
            $booking->work_environment_beneficiary_presence = null;
            $booking->female_worker_safety_pledge_accepted = false;
            $booking->female_worker_safety_pledge_accepted_at = null;
            $booking->female_worker_safety_pledge_version = null;
            $booking->female_worker_safety_pledge_text = null;

            return;
        }

        $confirmation = request()->input('workEnvironmentConfirmation');

        if (! is_array($confirmation)) {
            return;
        }

        $policy = app(FemaleWorkerSafetyPolicyService::class);

        $booking->work_environment_beneficiary_presence = (string) ($confirmation['beneficiaryPresence'] ?? '');
        $booking->female_worker_safety_pledge_accepted = (bool) ($confirmation['pledgeAccepted'] ?? false);
        $booking->female_worker_safety_pledge_accepted_at = now();
        $booking->female_worker_safety_pledge_version = (string) ($confirmation['pledgeVersion'] ?? $policy->version());
        $booking->female_worker_safety_pledge_text = $policy->pledgeText();
    }

    private function chargeAdminCommission(CleaningBooking $booking): void
    {
        try {
            $depositService = app(DepositService::class);
            $assignments = $booking->acceptedWorkerAssignments()->with('worker.deposit')->get();

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

            $worker = $booking->worker;
            $amount = (float) ($booking->admin_margin_amount ?? 0);
            if ($worker instanceof Worker && $amount > 0) {
                $depositService->recordAdminFeeDebit($worker, $booking, $amount);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function dispatchPreferredWorkerFallbackIfNeeded(CleaningBooking $booking): void
    {
        if ($booking->resolvedAssignmentMode() !== CleaningAssignmentMode::PreferredWorker->value || $booking->preferred_worker_id === null) {
            return;
        }

        $timeoutMinutes = max(1, (int) config('cleaning.preferred_worker_response_timeout_minutes', 20));
        $fallbackRatio = max(0.1, min(1.0, (float) config('cleaning.preferred_worker_fallback_after_ratio', 0.5)));
        $fallbackDelayMinutes = max(1, (int) ceil($timeoutMinutes * $fallbackRatio));

        ConvertPreferredCleaningBookingToOpenJob::dispatch($booking->id)
            ->delay(now()->addMinutes($fallbackDelayMinutes))
            ->afterCommit();
    }

    private function notifyLifecycleCreated(CleaningBooking $booking): void
    {
        $this->notifyBothParties($booking, 'cleaning.booking.created', null);
    }

    private function notifyLifecycleUpdated(CleaningBooking $booking, ?string $fromStatus): void
    {
        $this->notifyBothParties($booking, 'cleaning.booking.updated', $fromStatus);
    }

    private function notifyBothParties(CleaningBooking $booking, string $canonicalType, ?string $fromStatus): void
    {
        $customer = $booking->customer;
        $workerUser = $booking->worker?->user;

        if ($customer instanceof User) {
            $customer->notify(new BookingLifecycleNotification($booking, $canonicalType, 'worker', 'customer', $fromStatus));
        }

        if ($workerUser instanceof User) {
            $workerUser->notify(new BookingLifecycleNotification($booking, $canonicalType, 'customer', 'worker', $fromStatus));
        }
    }
}
