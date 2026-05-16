<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\Carbon;
use App\Support\Broadcast\BroadcastAfterResponse;
use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Events\ArrivalVerified;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class UserCleaningOrderService
{
    private const MAX_SECURITY_CODE_ATTEMPTS = 5;

    public function __construct(
        private UserCleaningOrderEstimationService $estimationService,
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
        $this->dispatchTrackingUpdate($updated);

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

            return $updated;
        });
    }

    public function confirmCompletion(CleaningBooking $booking): CleaningBooking
    {
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

        return $updated;
    }

    public function rejectCompletion(CleaningBooking $booking): CleaningBooking
    {
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

        return $updated;
    }

    public function requestCompletionExtension(CleaningBooking $booking): CleaningBooking
    {
        if ($booking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
            throw ValidationException::withMessages([
                'status' => ['Order is not waiting for completion confirmation.'],
            ]);
        }

        $booking->update([
            'status' => CleaningBookingStatus::TimeExtensionRequested,
        ]);

        $updated = $booking->fresh();
        $this->dispatchTrackingUpdate($updated);
        BroadcastAfterResponse::send(new CompletionDecisionMade(
            $updated->id,
            $updated->worker_id,
            'extension_requested',
            null,
            now()->toIso8601String(),
        ));

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
}
