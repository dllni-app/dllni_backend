<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\Carbon;
use App\Enums\GenderPreference;
use App\Models\CleaningFinancialSetting;
use App\Support\Broadcast\BroadcastAfterResponse;
use App\Models\BookingReview;
use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Events\ArrivalVerified;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class UserCleaningOrderService
{
    private const MAX_SECURITY_CODE_ATTEMPTS = 5;

    public function __construct(
        private UserCleaningOrderEstimationService $estimationService,
        private CleaningLifecycleNotificationService $lifecycleNotifications,
    ) {}

    public function store(User $user, array $validated): CleaningBooking
    {
        return DB::transaction(function () use ($user, $validated): CleaningBooking {
            $normalizedPropertyType = $this->estimationService->normalizePropertyType((string) $validated['propertyType']);
            $serviceIds = isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null;
            $normalizedPropertyDetails = $this->estimationService->normalizePropertyDetailsForStorage($normalizedPropertyType, (array) $validated['propertyDetails']);
            $normalizedInput = $this->estimationService->pricingSnapshotInput(
                $normalizedPropertyType,
                $normalizedPropertyDetails,
                $validated['addressLatitude'] ?? null,
                $validated['addressLongitude'] ?? null,
                $validated['preferredWorkerId'] ?? null,
                $serviceIds,
            );

            try {
                $estimation = $this->estimationService->estimate(
                    $normalizedInput['propertyType'],
                    $normalizedInput['propertyDetails'],
                    $normalizedInput['serviceIds'],
                );
                $pricing = $this->estimationService->price(
                    $normalizedInput['propertyType'],
                    $normalizedInput['propertyDetails'],
                    $normalizedInput['addressLatitude'],
                    $normalizedInput['addressLongitude'],
                    $normalizedInput['preferredWorkerId'],
                    $normalizedInput['serviceIds'],
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'pricing' => [$exception->getMessage()],
                ]);
            }

            $suggestedWorkers = (int) ($estimation['recommendation']['suggestedTeamSize'] ?? 1);
            $booking = CleaningBooking::create([
                'customer_id' => $user->id,
                'worker_id' => null,
                'preferred_worker_id' => $normalizedInput['preferredWorkerId'],
                'number_of_workers' => (int) ($validated['numberOfWorkers'] ?? $suggestedWorkers),
                'gender_preference' => $validated['genderPreference'] ?? GenderPreference::Any->value,
                'cancellation_policy_id' => $validated['cancellationPolicyId'] ?? $this->defaultCancellationPolicyId(),
                'billing_policy_id' => $validated['billingPolicyId'] ?? $this->defaultBillingPolicyId(),
                'booking_number' => $this->generateBookingNumber(),
                'status' => CleaningBookingStatus::Pending,
                'property_type' => $normalizedPropertyType,
                'property_details' => $normalizedPropertyDetails,
                'address_latitude' => $normalizedInput['addressLatitude'],
                'address_longitude' => $normalizedInput['addressLongitude'],
                'estimated_sqm' => $estimation['estimatedSqm'],
                'estimated_hours' => $estimation['estimatedHours'],
                'scheduled_date' => $validated['scheduledDate'],
                'scheduled_time' => $validated['scheduledTime'],
                'total_hours' => $estimation['estimatedHours'],
                'base_price' => $pricing['basePrice'],
                'addons_total' => $pricing['addonsTotal'],
                'travel_fee' => $pricing['travelFee'],
                'travel_distance_km' => $pricing['distanceKm'],
                'admin_margin_amount' => $pricing['adminMargin'],
                'is_pricing_final' => $pricing['isPricingFinal'],
                'cancellation_fee' => 0,
                'total_price' => $pricing['totalPrice'],
                'terms_accepted' => true,
            ]);

            $this->syncBookingServicesFromPricing($booking, (array) ($pricing['serviceLines'] ?? []));

            return $booking->fresh();
        });
    }

    public function update(CleaningBooking $booking, array $validated): CleaningBooking
    {
        if (in_array($booking->status, [CleaningBookingStatus::InProgress, CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'order' => ['Order cannot be edited in current status.'],
            ]);
        }

        return DB::transaction(function () use ($booking, $validated): CleaningBooking {
            $updates = [];
            $pricingFieldsChanged = false;
            $servicesChanged = false;
            $propertyTypeChanged = false;

            if (array_key_exists('propertyType', $validated)) {
                $updates['property_type'] = $this->estimationService->normalizePropertyType((string) $validated['propertyType']);
                $pricingFieldsChanged = true;
                $propertyTypeChanged = true;
            }

            if (array_key_exists('propertyDetails', $validated)) {
                $effectivePropertyType = (string) ($updates['property_type'] ?? $booking->property_type);
                $updates['property_details'] = $this->estimationService->normalizePropertyDetailsForStorage($effectivePropertyType, (array) $validated['propertyDetails']);
                $pricingFieldsChanged = true;
            }
            if (array_key_exists('scheduledDate', $validated)) {
                $updates['scheduled_date'] = $validated['scheduledDate'];
            }
            if (array_key_exists('scheduledTime', $validated)) {
                $updates['scheduled_time'] = $validated['scheduledTime'];
            }
            if (array_key_exists('addressLatitude', $validated)) {
                $updates['address_latitude'] = $validated['addressLatitude'];
                $pricingFieldsChanged = true;
            }
            if (array_key_exists('addressLongitude', $validated)) {
                $updates['address_longitude'] = $validated['addressLongitude'];
                $pricingFieldsChanged = true;
            }
            if (array_key_exists('preferredWorkerId', $validated)) {
                $updates['preferred_worker_id'] = $validated['preferredWorkerId'];
                $pricingFieldsChanged = true;
            }
            if (array_key_exists('numberOfWorkers', $validated)) {
                $updates['number_of_workers'] = (int) ($validated['numberOfWorkers'] ?? 1);
            }
            if (array_key_exists('genderPreference', $validated)) {
                $updates['gender_preference'] = $validated['genderPreference'] ?? GenderPreference::Any->value;
            }
            if (array_key_exists('serviceIds', $validated)) {
                $servicesChanged = true;
                $pricingFieldsChanged = true;
            }

            if ($pricingFieldsChanged) {
                $propertyType = (string) ($updates['property_type'] ?? $booking->property_type);
                $propertyDetails = (array) ($updates['property_details'] ?? $booking->property_details ?? []);
                $addressLatitude = $updates['address_latitude'] ?? $booking->address_latitude;
                $addressLongitude = $updates['address_longitude'] ?? $booking->address_longitude;
                $preferredWorkerId = $updates['preferred_worker_id'] ?? $booking->preferred_worker_id;

                $serviceIds = $servicesChanged
                    ? (array) ($validated['serviceIds'] ?? [])
                    : ($propertyTypeChanged
                        ? []
                        : $booking->services()->pluck('cleaning_services.id')->all());

                $normalizedInput = $this->estimationService->pricingSnapshotInput(
                    $propertyType,
                    $propertyDetails,
                    $addressLatitude,
                    $addressLongitude,
                    $preferredWorkerId,
                    $serviceIds,
                );
                try {
                    $estimation = $this->estimationService->estimate(
                        $normalizedInput['propertyType'],
                        $normalizedInput['propertyDetails'],
                        $normalizedInput['serviceIds']
                    );
                    $pricing = $this->estimationService->price(
                        $normalizedInput['propertyType'],
                        $normalizedInput['propertyDetails'],
                        $normalizedInput['addressLatitude'],
                        $normalizedInput['addressLongitude'],
                        $normalizedInput['preferredWorkerId'],
                        $normalizedInput['serviceIds'],
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        'pricing' => [$exception->getMessage()],
                    ]);
                }

                $updates['property_type'] = $normalizedInput['propertyType'];
                if (array_key_exists('propertyDetails', $validated)) {
                    $updates['property_details'] = $this->estimationService->normalizePropertyDetailsForStorage(
                        $normalizedInput['propertyType'],
                        (array) $propertyDetails
                    );
                }
                if (array_key_exists('addressLatitude', $validated)) {
                    $updates['address_latitude'] = $normalizedInput['addressLatitude'];
                }
                if (array_key_exists('addressLongitude', $validated)) {
                    $updates['address_longitude'] = $normalizedInput['addressLongitude'];
                }
                if (array_key_exists('preferredWorkerId', $validated)) {
                    $updates['preferred_worker_id'] = $normalizedInput['preferredWorkerId'];
                }

                $updates['estimated_sqm'] = $estimation['estimatedSqm'];
                $updates['estimated_hours'] = $estimation['estimatedHours'];
                $updates['total_hours'] = $estimation['estimatedHours'];
                $updates['base_price'] = $pricing['basePrice'];
                $updates['travel_fee'] = $pricing['travelFee'];
                $updates['travel_distance_km'] = $pricing['distanceKm'];
                $updates['addons_total'] = $pricing['addonsTotal'];
                $updates['admin_margin_amount'] = $pricing['adminMargin'];
                $updates['is_pricing_final'] = $pricing['isPricingFinal'];
                $updates['total_price'] = $pricing['totalPrice'];
                $this->syncBookingServicesFromPricing($booking, (array) ($pricing['serviceLines'] ?? []));

                if (
                    $this->estimationService->isEventAssistanceType($normalizedInput['propertyType'])
                    && ! array_key_exists('numberOfWorkers', $validated)
                    && ! array_key_exists('number_of_workers', $updates)
                ) {
                    $updates['number_of_workers'] = (int) ($estimation['recommendation']['suggestedTeamSize'] ?? 1);
                }
            }

            if ($updates !== []) {
                $booking->update($updates);
            }

            return $booking->fresh();
        });
    }

    public function cancel(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        if (! in_array($booking->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true)) {
            throw ValidationException::withMessages([
                'order' => ['Order cannot be cancelled in current status.'],
            ]);
        }

        $booking->update([
            'status' => CleaningBookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $updated = $booking->fresh();
        $this->dispatchTrackingUpdate($updated);
        $this->lifecycleNotifications->notifyWorker(
            booking: $updated,
            canonicalType: 'cleaning.booking.order_cancelled',
            action: 'customer_cancelled',
            actorRole: 'customer',
            fromStatus: $fromStatus,
            occurredAt: $updated->cancelled_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    public function confirmStartVerification(CleaningBooking $booking, string $code): CleaningBooking
    {
        if ($booking->status !== CleaningBookingStatus::AwaitingStartVerification) {
            throw ValidationException::withMessages([
                'status' => ['Order is not waiting for start verification.'],
            ]);
        }

        return DB::transaction(function () use ($booking, $code): CleaningBooking {
            $record = DB::table('booking_security_codes')
                ->where('booking_id', $booking->id)
                ->where('booking_type', $booking->getMorphClass())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw ValidationException::withMessages([
                    'code' => ['Security code is not available for this order.'],
                ]);
            }

            if (($record->consumed_at ?? null) !== null) {
                return $booking->fresh();
            }

            if (now()->greaterThan(Carbon::parse((string) $record->expires_at))) {
                throw ValidationException::withMessages([
                    'code' => ['Security code has expired.'],
                ]);
            }

            if ((int) ($record->attempts ?? 0) >= self::MAX_SECURITY_CODE_ATTEMPTS) {
                throw new HttpException(429, 'Too many failed verification attempts. Please try again later.');
            }

            $providedHash = hash_hmac('sha256', $code, (string) config('app.key'));
            $expectedHash = (string) ($record->code_hash ?? '');
            $legacyCode = (string) ($record->code ?? '');

            $isMatch = $expectedHash !== ''
                ? hash_equals($expectedHash, $providedHash)
                : hash_equals($legacyCode, $code);

            if (! $isMatch) {
                DB::table('booking_security_codes')
                    ->where('id', $record->id)
                    ->update([
                        'attempts' => ((int) $record->attempts) + 1,
                        'last_attempt_at' => now(),
                        'updated_at' => now(),
                    ]);

                throw ValidationException::withMessages([
                    'code' => ['Invalid security code.'],
                ]);
            }

            DB::table('booking_security_codes')
                ->where('id', $record->id)
                ->update([
                    'attempts' => ((int) $record->attempts) + 1,
                    'consumed_at' => now(),
                    'last_attempt_at' => now(),
                    'updated_at' => now(),
                ]);

            $booking->update([
                'status' => CleaningBookingStatus::InProgress,
                'work_started_at' => now(),
                'customer_confirmed_at' => now(),
            ]);

            $updated = $booking->fresh();

            $this->dispatchTrackingUpdate($updated);
            BroadcastAfterResponse::send(new ArrivalVerified(
                $updated->id,
                $updated->worker_id,
                (string) $updated->arrived_at?->toIso8601String(),
            ));
            $this->lifecycleNotifications->notifyWorker(
                booking: $updated,
                canonicalType: 'cleaning.booking.start_verified',
                action: 'start_verified',
                actorRole: 'customer',
                fromStatus: CleaningBookingStatus::AwaitingStartVerification->value,
                occurredAt: $updated->customer_confirmed_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
            );

            return $updated;
        });
    }

    public function confirmCompletion(CleaningBooking $booking): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        if ($booking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
            throw ValidationException::withMessages([
                'status' => ['Order is not waiting for completion confirmation.'],
            ]);
        }

        $booking->update([
            'status' => CleaningBookingStatus::Completed,
            'customer_confirmed_at' => now(),
        ]);

        $updated = $booking->fresh();
        $this->dispatchTrackingUpdate($updated);
        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $updated->id,
            $updated->worker_id,
            'approved',
            null,
            now()->toIso8601String(),
        ));
        $this->lifecycleNotifications->notifyWorker(
            booking: $updated,
            canonicalType: 'cleaning.booking.completion_approved',
            action: 'completion_approved',
            actorRole: 'customer',
            fromStatus: $fromStatus,
            occurredAt: $updated->customer_confirmed_at?->toIso8601String() ?? $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    public function rejectCompletion(CleaningBooking $booking): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        if ($booking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
            throw ValidationException::withMessages([
                'status' => ['Order is not waiting for completion confirmation.'],
            ]);
        }

        $booking->update([
            'status' => CleaningBookingStatus::InProgress,
            'work_finished_at' => null,
        ]);

        $updated = $booking->fresh();
        $this->dispatchTrackingUpdate($updated);
        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $updated->id,
            $updated->worker_id,
            'rejected',
            null,
            now()->toIso8601String(),
        ));
        $this->lifecycleNotifications->notifyWorker(
            booking: $updated,
            canonicalType: 'cleaning.booking.completion_rejected',
            action: 'completion_rejected',
            actorRole: 'customer',
            fromStatus: $fromStatus,
            occurredAt: $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    public function requestCompletionExtension(CleaningBooking $booking, int $additionalMinutes): CleaningBooking
    {
        $fromStatus = (string) $booking->status->value;

        if ($booking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
            throw ValidationException::withMessages([
                'status' => ['Order is not waiting for completion confirmation.'],
            ]);
        }

        $updated = DB::transaction(function () use ($booking, $additionalMinutes): CleaningBooking {
            $booking = CleaningBooking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
                throw ValidationException::withMessages([
                    'status' => ['Order is not waiting for completion confirmation.'],
                ]);
            }

            $quotedAmount = $this->calculateExtensionQuotedAmount($additionalMinutes);
            $quotedCurrency = (string) config('app.currency', 'SYP');

            CleaningTimeWarning::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
                'worker_response' => null,
                'sent_at' => now(),
                'customer_responded_at' => now(),
                'worker_responded_at' => null,
                'additional_minutes' => $additionalMinutes,
                'quoted_amount' => $quotedAmount,
                'quoted_currency' => $quotedCurrency,
                'price_applied_at' => null,
                'worker_reject_message' => null,
            ]);

            $booking->update([
                'status' => CleaningBookingStatus::TimeExtensionRequested,
            ]);

            return $booking->fresh();
        });

        $this->dispatchTrackingUpdate($updated);
        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $updated->id,
            $updated->worker_id,
            'extension_requested',
            null,
            now()->toIso8601String(),
        ));
        $this->lifecycleNotifications->notifyWorker(
            booking: $updated,
            canonicalType: 'cleaning.booking.time_extension_requested',
            action: 'time_extension_requested',
            actorRole: 'customer',
            fromStatus: $fromStatus,
            occurredAt: $updated->updated_at?->toIso8601String(),
        );

        return $updated;
    }

    private function calculateExtensionQuotedAmount(int $additionalMinutes): float
    {
        $ratePerThirtyMinutes = $this->extensionRatePerThirtyMinutes();

        if ($ratePerThirtyMinutes <= 0) {
            return 0.0;
        }

        return round(($ratePerThirtyMinutes / 30) * $additionalMinutes, 2);
    }

    private function extensionRatePerThirtyMinutes(): float
    {
        $value = CleaningFinancialSetting::query()->value('extension_rate_per_30_minutes');

        return $value === null ? 0.0 : (float) $value;
    }

    /**
     * @param array{rating:int,comment?:string|null} $validated
     */
    public function submitReview(CleaningBooking $booking, array $validated): BookingReview
    {
        if ($booking->status !== CleaningBookingStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['Review can only be submitted for completed orders.'],
            ]);
        }

        /** @var BookingReview $review */
        $review = BookingReview::query()->updateOrCreate(
            [
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'customer_id' => $booking->customer_id,
            ],
            [
                'rating' => (int) $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]
        );

        return $review;
    }

    private function defaultCancellationPolicyId(): ?int
    {
        $id = CancellationPolicy::query()
            ->where('module', 'cleaning')
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function defaultBillingPolicyId(): ?int
    {
        $id = CleaningBillingPolicy::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function generateBookingNumber(): string
    {
        do {
            $bookingNumber = 'CLN-USER-' . Str::upper(Str::random(8));
        } while (CleaningBooking::query()->where('booking_number', $bookingNumber)->exists());

        return $bookingNumber;
    }

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $booking->status?->value,
            'workerId' => $booking->worker_id,
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  array<int, array{cleaningServiceId:int,quantity:float,unitPrice:float,totalPrice:float}>  $serviceLines
     */
    private function syncBookingServicesFromPricing(CleaningBooking $booking, array $serviceLines): void
    {
        $syncPayload = [];

        foreach ($serviceLines as $line) {
            $serviceId = (int) ($line['cleaningServiceId'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $syncPayload[$serviceId] = [
                'quantity' => round((float) ($line['quantity'] ?? 1), 2),
                'unit_price' => round((float) ($line['unitPrice'] ?? 0), 2),
                'total_price' => round((float) ($line['totalPrice'] ?? 0), 2),
            ];
        }

        $booking->services()->sync($syncPayload);
    }
}
