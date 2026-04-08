<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

final class UserCleaningOrderService
{
    public function __construct(
        private UserCleaningOrderEstimationService $estimationService,
        private UserCleaningOrderQuoteService $quoteService,
    ) {}

    public function store(User $user, array $validated): CleaningBooking
    {
        return DB::transaction(function () use ($user, $validated): CleaningBooking {
            $normalizedPropertyType = $this->estimationService->normalizePropertyType((string) $validated['propertyType']);
            $normalizedPropertyDetails = $this->estimationService->normalizePropertyDetailsForStorage((array) $validated['propertyDetails']);
            $normalizedInput = $this->estimationService->pricingSnapshotInput(
                $normalizedPropertyType,
                $normalizedPropertyDetails,
                $validated['addressLatitude'] ?? null,
                $validated['addressLongitude'] ?? null,
                $validated['preferredWorkerId'] ?? null
            );

            $estimation = $this->estimationService->estimate(
                $normalizedInput['propertyType'],
                $normalizedInput['propertyDetails']
            );
            $pricing = $this->estimationService->price(
                $normalizedInput['propertyType'],
                $normalizedInput['propertyDetails'],
                $normalizedInput['addressLatitude'],
                $normalizedInput['addressLongitude'],
                $normalizedInput['preferredWorkerId']
            );

            $this->quoteService->validateQuote(
                $validated['quoteId'] ?? null,
                (int) $user->id,
                $normalizedInput,
                $estimation,
                $pricing,
                $this->quoteService->isQuoteRequiredNow(),
            );

            $booking = CleaningBooking::create([
                'customer_id' => $user->id,
                'worker_id' => null,
                'preferred_worker_id' => $normalizedInput['preferredWorkerId'],
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
                'cancellation_fee' => 0,
                'total_price' => $pricing['totalPrice'],
                'terms_accepted' => true,
            ]);

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

            if (array_key_exists('propertyType', $validated)) {
                $updates['property_type'] = $this->estimationService->normalizePropertyType((string) $validated['propertyType']);
                $pricingFieldsChanged = true;
            }

            if (array_key_exists('propertyDetails', $validated)) {
                $updates['property_details'] = $this->estimationService->normalizePropertyDetailsForStorage((array) $validated['propertyDetails']);
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

            if ($pricingFieldsChanged) {
                $propertyType = (string) ($updates['property_type'] ?? $booking->property_type);
                $propertyDetails = (array) ($updates['property_details'] ?? $booking->property_details ?? []);
                $addressLatitude = $updates['address_latitude'] ?? $booking->address_latitude;
                $addressLongitude = $updates['address_longitude'] ?? $booking->address_longitude;
                $preferredWorkerId = $updates['preferred_worker_id'] ?? $booking->preferred_worker_id;

                $normalizedInput = $this->estimationService->pricingSnapshotInput(
                    $propertyType,
                    $propertyDetails,
                    $addressLatitude,
                    $addressLongitude,
                    $preferredWorkerId
                );
                $estimation = $this->estimationService->estimate($normalizedInput['propertyType'], $normalizedInput['propertyDetails']);
                $pricing = $this->estimationService->price(
                    $normalizedInput['propertyType'],
                    $normalizedInput['propertyDetails'],
                    $normalizedInput['addressLatitude'],
                    $normalizedInput['addressLongitude'],
                    $normalizedInput['preferredWorkerId']
                );

                $this->quoteService->validateQuote(
                    $validated['quoteId'] ?? null,
                    (int) $booking->customer_id,
                    $normalizedInput,
                    $estimation,
                    $pricing,
                    $this->quoteService->isQuoteRequiredNow(),
                );

                $updates['property_type'] = $normalizedInput['propertyType'];
                if (array_key_exists('propertyDetails', $validated)) {
                    $updates['property_details'] = $this->estimationService->normalizePropertyDetailsForStorage((array) $propertyDetails);
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
                $updates['addons_total'] = $pricing['addonsTotal'];
                $updates['total_price'] = $pricing['totalPrice'];
            }

            if ($updates !== []) {
                $booking->update($updates);
            }

            return $booking->fresh();
        });
    }

    public function cancel(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
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

        CleaningBookingTrackingUpdated::dispatch($updated->id, [
            'cleaningBookingId' => $updated->id,
            'status' => $updated->status?->value,
            'workerId' => $updated->worker_id,
            'startedTravelAt' => $updated->started_travel_at?->toIso8601String(),
            'arrivedAt' => $updated->arrived_at?->toIso8601String(),
            'workStartedAt' => $updated->work_started_at?->toIso8601String(),
            'workFinishedAt' => $updated->work_finished_at?->toIso8601String(),
            'cancelledAt' => $updated->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]);

        return $updated;
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
            $bookingNumber = 'CLN-USER-'.Str::upper(Str::random(8));
        } while (CleaningBooking::query()->where('booking_number', $bookingNumber)->exists());

        return $bookingNumber;
    }
}
