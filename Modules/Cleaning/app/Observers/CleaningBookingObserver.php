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
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\DepositService;
use Modules\User\Services\FemaleWorkerSafetyPolicyService;
use Throwable;

final class CleaningBookingObserver
{
    private const HOT_ORDER_PREFIX = '[🚨 طلب ساخن - تنفيذ فوري عاجل]';

    public function creating(CleaningBooking $booking): void
    {
        $this->applyWorkEnvironmentSnapshot($booking);
        $this->applySameDayHotOrderSnapshot($booking);
    }

    public function updating(CleaningBooking $booking): void
    {
        if ($booking->isDirty('gender_preference')) {
            $this->applyWorkEnvironmentSnapshot($booking);
        }

        if ($booking->isDirty('scheduled_date') || $booking->isDirty('property_details') || $booking->isDirty('property_type')) {
            $this->applySameDayHotOrderSnapshot($booking);
        }

        if ($booking->isDirty('status')) {
            $this->applyCancellationSourceSnapshot($booking);
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

    private function applySameDayHotOrderSnapshot(CleaningBooking $booking): void
    {
        $propertyDetails = is_array($booking->property_details) ? $booking->property_details : [];
        $baseTitle = $this->stripHotOrderPrefix($this->resolveBaseOrderTitle($booking, $propertyDetails));

        if (! $this->isScheduledForToday($booking)) {
            $propertyDetails['is_hot_order'] = false;
            $propertyDetails['hot_order_label'] = null;
            $propertyDetails['hot_order_prefix'] = null;
            $propertyDetails['hot_order_title'] = null;
            $propertyDetails['order_title'] = $baseTitle;
            $propertyDetails['title'] = $baseTitle;
            $booking->property_details = $propertyDetails;

            return;
        }

        $hotTitle = self::HOT_ORDER_PREFIX.' '.$baseTitle;

        $propertyDetails['is_hot_order'] = true;
        $propertyDetails['hot_order_label'] = self::HOT_ORDER_PREFIX;
        $propertyDetails['hot_order_prefix'] = self::HOT_ORDER_PREFIX;
        $propertyDetails['hot_order_title'] = $hotTitle;
        $propertyDetails['order_title'] = $hotTitle;
        $propertyDetails['title'] = $hotTitle;

        $booking->property_details = $propertyDetails;
    }

    private function applyCancellationSourceSnapshot(CleaningBooking $booking): void
    {
        $currentStatus = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);

        if ($currentStatus !== CleaningBookingStatus::Cancelled || filled($booking->cancelled_by_role)) {
            return;
        }

        $fromStatus = $booking->getOriginal('status');
        $fromStatusValue = $fromStatus instanceof BackedEnum ? $fromStatus->value : (string) $fromStatus;

        if (! in_array($fromStatusValue, [
            CleaningBookingStatus::AwaitingStartVerification->value,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation->value,
        ], true)) {
            return;
        }

        $booking->cancelled_by_role = Auth::user()?->worker instanceof Worker ? 'worker' : 'customer';
    }

    private function isScheduledForToday(CleaningBooking $booking): bool
    {
        $scheduledDate = $booking->scheduled_date;

        if ($scheduledDate instanceof CarbonInterface) {
            return $scheduledDate->isSameDay(now());
        }

        if (is_string($scheduledDate) && $scheduledDate !== '') {
            return now()->toDateString() === mb_substr($scheduledDate, 0, 10);
        }

        return false;
    }

    /** @param array<string, mixed> $propertyDetails */
    private function resolveBaseOrderTitle(CleaningBooking $booking, array $propertyDetails): string
    {
        foreach (['original_order_title', 'order_title', 'title', 'customService', 'custom_service', 'location_name', 'address'] as $key) {
            $value = $propertyDetails[$key] ?? null;
            if (is_string($value) && mb_trim($value) !== '') {
                return mb_trim($value);
            }
        }

        $bookingNumber = is_string($booking->booking_number) && $booking->booking_number !== ''
            ? $booking->booking_number
            : 'تنظيف';

        return 'طلب تنظيف '.$bookingNumber;
    }

    private function stripHotOrderPrefix(string $title): string
    {
        $normalized = mb_trim($title);

        return mb_trim(str_replace(self::HOT_ORDER_PREFIX, '', $normalized));
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
        if ($booking->preferred_worker_id === null || $booking->resolvedAssignmentMode() !== CleaningAssignmentMode::PreferredWorker->value) {
            return;
        }

        $delayMinutes = (int) config('cleaning.preferred_worker_fallback_minutes', 10);
        if ($delayMinutes <= 0) {
            return;
        }

        ConvertPreferredCleaningBookingToOpenJob::dispatch($booking->id)->delay(now()->addMinutes($delayMinutes))->afterCommit();
    }

    private function notifyLifecycleCreated(CleaningBooking $booking): void
    {
        $booking->loadMissing(['customer', 'worker.user']);

        $this->notifyLifecycleRecipient(
            recipient: $booking->customer,
            booking: $booking,
            targetRole: 'customer',
            canonicalType: 'cleaning.booking.created',
            action: 'created',
            fromStatus: null,
            occurredAt: $booking->created_at?->toIso8601String() ?? now()->toIso8601String(),
        );

        $this->notifyLifecycleRecipient(
            recipient: $booking->worker?->user,
            booking: $booking,
            targetRole: 'worker',
            canonicalType: 'cleaning.booking.created',
            action: 'created',
            fromStatus: null,
            occurredAt: $booking->created_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }

    private function notifyLifecycleUpdated(CleaningBooking $booking, string $fromStatus): void
    {
        $booking->loadMissing(['customer', 'worker.user']);

        $this->notifyLifecycleRecipient(
            recipient: $booking->customer,
            booking: $booking,
            targetRole: 'customer',
            canonicalType: 'cleaning.booking.updated',
            action: 'updated',
            fromStatus: $fromStatus,
            occurredAt: $booking->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );

        $this->notifyLifecycleRecipient(
            recipient: $booking->worker?->user,
            booking: $booking,
            targetRole: 'worker',
            canonicalType: 'cleaning.booking.updated',
            action: 'updated',
            fromStatus: $fromStatus,
            occurredAt: $booking->updated_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }

    private function notifyLifecycleRecipient(
        ?User $recipient,
        CleaningBooking $booking,
        string $targetRole,
        string $canonicalType,
        string $action,
        ?string $fromStatus,
        string $occurredAt,
    ): void {
        if (! $recipient instanceof User) {
            return;
        }

        $recipient->notify(new BookingLifecycleNotification(
            canonicalType: $canonicalType,
            action: $action,
            booking: $booking,
            actorRole: 'system',
            targetRole: $targetRole,
            fromStatus: $fromStatus,
            occurredAt: $occurredAt,
        ));
    }
}
