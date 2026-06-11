<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\Carbon;
use App\Enums\GenderPreference;
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
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class UserCleaningOrderService
{
    private const MAX_SECURITY_CODE_ATTEMPTS = 5;

    public function __construct(
        private UserCleaningOrderEstimationService $estimationService,
        private CleaningBookingTeamService $teamService,
        private CleaningLifecycleNotificationService $lifecycleNotifications,
        private CleaningExtendedTimePricingService $extendedTimePricing,
    ) {}

    public function store(User $user, array $validated): CleaningBooking
    {
        return DB::transaction(function () use ($user, $validated): CleaningBooking {
            $normalizedPropertyType = $this->estimationService->normalizePropertyType((string) $validated['propertyType']);
            $normalizedPropertyDetails = $this->estimationService->normalizePropertyDetailsForStorage($normalizedPropertyType, (array) $validated['propertyDetails']);
            $resolvedAssignmentMode = $this->resolveAssignmentMode($validated);
            $preferredWorkerPricing = $resolvedAssignmentMode === 'preferred_worker';
            $pricingPreferredWorkerId = $preferredWorkerPricing ? ($validated['preferredWorkerId'] ?? null) : null;
            $normalizedInput = $this->estimationService->pricingSnapshotInput(
                $normalizedPropertyType,
                $normalizedPropertyDetails,
                $validated['addressLatitude'] ?? null,
                $validated['addressLongitude'] ?? null,
                $pricingPreferredWorkerId,
            );

            try {
                $estimation = $this->estimationService->estimate(
                    $normalizedInput['propertyType'],
                    $normalizedInput['propertyDetails'],
                );
                $pricing = $this->estimationService->price(
                    $normalizedInput['propertyType'],
                    $normalizedInput['propertyDetails'],
                    $normalizedInput['addressLatitude'],
                    $normalizedInput['addressLongitude'],
                    $pricingPreferredWorkerId,
                );
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'pricing' => [$exception->getMessage()],
                ]);
            }

            $suggestedWorkers = (int) ($estimation['recommendation']['suggestedTeamSize'] ?? 1);
            $requestedWorkers = $this->resolveRequestedWorkers(
                $validated,
                $normalizedPropertyType,
                $suggestedWorkers,
                $resolvedAssignmentMode
            );
            $plannedWorkerRoomAssignments = $this->plannedWorkerRoomAssignments(
                $normalizedPropertyType,
                $normalizedPropertyDetails,
                $validated['workerRoomAssignments'] ?? null,
                $resolvedAssignmentMode,
                $requestedWorkers,
                $resolvedAssignmentMode === 'preferred_worker'
                    ? ($normalizedInput['preferredWorkerId'] !== null ? (int) $normalizedInput['preferredWorkerId'] : null)
                    : null,
            );
            $storedPricing = $preferredWorkerPricing ? $pricing : [
                'basePrice' => $pricing['basePrice'],
                'addonsTotal' => $pricing['addonsTotal'],
                'travelFee' => 0.0,
                'distanceKm' => null,
                'adminMargin' => 0.0,
                'isPricingFinal' => false,
                'totalPrice' => round((float) $pricing['basePrice'] + (float) $pricing['addonsTotal'], 2),
            ];
            $booking = CleaningBooking::create([
                'customer_id' => $user->id,
                'worker_id' => null,
                'preferred_worker_id' => $resolvedAssignmentMode === 'preferred_worker'
                    ? $normalizedInput['preferredWorkerId']
                    : null,
                'assignment_mode' => $resolvedAssignmentMode,
                'number_of_workers' => $requestedWorkers,
                'gender_preference' => $validated['genderPreference'] ?? GenderPreference::Any->value,
                'cancellation_policy_id' => $validated['cancellationPolicyId'] ?? $this->defaultCancellationPolicyId(),
                'billing_policy_id' => $validated['billingPolicyId'] ?? $this->defaultBillingPolicyId(),
                'booking_number' => $this->generateBookingNumber(),
                'status' => CleaningBookingStatus::Pending,
                'property_type' => $normalizedPropertyType,
                'property_details' => $normalizedPropertyDetails,
                'cleaning_services' => $this->normalizeCleaningServices($validated['cleaning_services'] ?? null),
                'address_latitude' => $normalizedInput['addressLatitude'],
                'address_longitude' => $normalizedInput['addressLongitude'],
                'estimated_sqm' => $estimation['estimatedSqm'],
                'estimated_hours' => $estimation['estimatedHours'],
                'scheduled_date' => $validated['scheduledDate'],
                'scheduled_time' => $validated['scheduledTime'],
                'total_hours' => $estimation['estimatedHours'],
                'base_price' => $storedPricing['basePrice'],
                'addons_total' => $storedPricing['addonsTotal'],
                'travel_fee' => $storedPricing['travelFee'],
                'travel_distance_km' => $storedPricing['distanceKm'],
                'admin_margin_amount' => $storedPricing['adminMargin'],
                'is_pricing_final' => $storedPricing['isPricingFinal'],
                'cancellation_fee' => 0,
                'total_price' => $storedPricing['totalPrice'],
                'terms_accepted' => true,
            ]);

            $this->teamService->syncRooms($booking, $plannedWorkerRoomAssignments);

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
            $booking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasAcceptedAssignments = $booking->workerAssignments()
                ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value)
                ->exists();

            if ($hasAcceptedAssignments && array_intersect(array_keys($validated), [
                'propertyType',
                'propertyDetails',
                'addressLatitude',
                'addressLongitude',
                'preferredWorkerId',
                'assignmentMode',
                'numberOfWorkers',
            ]) !== []) {
                throw ValidationException::withMessages([
                    'order' => ['Order cannot change room or pricing fields after workers have accepted.'],
                ]);
            }

            $updates = [];
            $pricingFieldsChanged = false;

            if (array_key_exists('propertyType', $validated)) {
                $updates['property_type'] = $this->estimationService->normalizePropertyType((string) $validated['propertyType']);
                $pricingFieldsChanged = true;
            }

            if (array_key_exists('propertyDetails', $validated)) {
                $effectivePropertyType = (string) ($updates['property_type'] ?? $booking->property_type);
                $mergedPropertyDetails = array_replace_recursive(
                    is_array($booking->property_details) ? $booking->property_details : [],
                    (array) $validated['propertyDetails']
                );
                $updates['property_details'] = $this->estimationService->normalizePropertyDetailsForStorage(
                    $effectivePropertyType,
                    $mergedPropertyDetails
                );
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

            if (
                array_key_exists('assignmentMode', $validated)
                || array_key_exists('numberOfWorkers', $validated)
                || array_key_exists('preferredWorkerId', $validated)
            ) {
                $updates['assignment_mode'] = $this->resolveAssignmentMode($validated, $booking);
                $pricingFieldsChanged = true;

                if (! array_key_exists('preferredWorkerId', $validated) && $updates['assignment_mode'] === 'open_count') {
                    $updates['preferred_worker_id'] = null;
                }
            }

            if (array_key_exists('numberOfWorkers', $validated)) {
                $updates['number_of_workers'] = $this->resolveRequestedWorkersForUpdate($booking, $validated);
            } elseif (array_key_exists('assignmentMode', $validated) && $this->normalizedAssignmentMode($validated['assignmentMode'] ?? null) === 'preferred_worker') {
                $updates['number_of_workers'] = 1;
            } elseif ($this->estimationService->isEventAssistanceType((string) ($updates['property_type'] ?? $booking->property_type)) && ! array_key_exists('number_of_workers', $updates)) {
                $updates['number_of_workers'] = (int) ($booking->number_of_workers ?? 1);
            }

            if (array_key_exists('genderPreference', $validated)) {
                $updates['gender_preference'] = $validated['genderPreference'] ?? GenderPreference::Any->value;
            }

            if (array_key_exists('cleaning_services', $validated)) {
                $updates['cleaning_services'] = $this->normalizeCleaningServices($validated['cleaning_services']);
            }

            if ($pricingFieldsChanged) {
                $propertyType = (string) ($updates['property_type'] ?? $booking->property_type);
                $propertyDetails = (array) ($updates['property_details'] ?? $booking->property_details ?? []);
                $addressLatitude = $updates['address_latitude'] ?? $booking->address_latitude;
                $addressLongitude = $updates['address_longitude'] ?? $booking->address_longitude;
                $preferredWorkerId = $this->resolvePreferredWorkerForPricing($validated, $booking, $updates);

                $normalizedInput = $this->estimationService->pricingSnapshotInput(
                    $propertyType,
                    $propertyDetails,
                    $addressLatitude,
                    $addressLongitude,
                    $preferredWorkerId,
                );
                try {
                    $estimation = $this->estimationService->estimate(
                        $normalizedInput['propertyType'],
                        $normalizedInput['propertyDetails'],
                    );
                    $pricing = $this->estimationService->price(
                        $normalizedInput['propertyType'],
                        $normalizedInput['propertyDetails'],
                        $normalizedInput['addressLatitude'],
                        $normalizedInput['addressLongitude'],
                        $normalizedInput['preferredWorkerId'],
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
                $storedPricing = $this->resolveStoredPricingForUpdate($pricing, $validated, $booking);
                $updates['base_price'] = $storedPricing['basePrice'];
                $updates['travel_fee'] = $storedPricing['travelFee'];
                $updates['travel_distance_km'] = $storedPricing['distanceKm'];
                $updates['addons_total'] = $storedPricing['addonsTotal'];
                $updates['admin_margin_amount'] = $storedPricing['adminMargin'];
                $updates['is_pricing_final'] = $storedPricing['isPricingFinal'];
                $updates['total_price'] = $storedPricing['totalPrice'];
                if (
                    $this->estimationService->isEventAssistanceType($normalizedInput['propertyType'])
                    && (! array_key_exists('numberOfWorkers', $validated) || $validated['numberOfWorkers'] === null)
                    && ! array_key_exists('number_of_workers', $updates)
                ) {
                    $updates['number_of_workers'] = max(1, (int) ($estimation['recommendation']['suggestedTeamSize'] ?? $booking->number_of_workers ?? 1));
                }
            }

            if ($updates !== []) {
                $booking->update($updates);
            }

            if ($pricingFieldsChanged || array_key_exists('assignmentMode', $validated) || array_key_exists('numberOfWorkers', $validated) || array_key_exists('propertyDetails', $validated) || array_key_exists('propertyType', $validated)) {
                $booking = $booking->fresh();

                if (! $hasAcceptedAssignments) {
                    $plannedWorkerRoomAssignments = array_key_exists('workerRoomAssignments', $validated)
                        ? $this->plannedWorkerRoomAssignments(
                            (string) $booking->property_type,
                            is_array($booking->property_details) ? $booking->property_details : [],
                            $validated['workerRoomAssignments'] ?? null,
                            $booking->resolvedAssignmentMode(),
                            max(1, (int) ($booking->number_of_workers ?? 1)),
                            $booking->preferred_worker_id !== null ? (int) $booking->preferred_worker_id : null,
                        )
                        : $this->teamService->exportPlannedWorkerRoomAssignments($booking);

                    $this->teamService->syncRooms($booking, $plannedWorkerRoomAssignments);
                }
            }

            return $booking->fresh();
        });
    }

    /**
     * @param  array<int, array{roomId:int, workerId:?int}>  $assignments
     */
    public function assignRoomAssignments(CleaningBooking $booking, array $assignments): CleaningBooking
    {
        return $this->teamService->assignRoomsFromCustomer($booking, $assignments);
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

            DB::table('cleaning_booking_worker_assignments')
                ->where('cleaning_booking_id', $booking->id)
                ->whereIn('status', [
                    CleaningBookingWorkerAssignmentStatus::Accepted->value,
                    CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
                ])
                ->update([
                    'status' => CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification->value,
                    'updated_at' => now(),
                ]);

            $assignmentCount = DB::table('cleaning_booking_worker_assignments')
                ->where('cleaning_booking_id', $booking->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->count();
            $startApproved = DB::table('cleaning_booking_worker_assignments')
                ->where('cleaning_booking_id', $booking->id)
                ->where('status', CleaningBookingWorkerAssignmentStatus::StartApproved->value)
                ->count();
            $required = max(1, (int) ($booking->number_of_workers ?? 1));
            $shouldStartImmediately = $assignmentCount === 0
                && $booking->worker_id !== null
                && $required <= 1;

            $booking->update([
                'status' => $shouldStartImmediately || $startApproved >= $required
                    ? CleaningBookingStatus::InProgress
                    : CleaningBookingStatus::AwaitingStartVerification,
                'work_started_at' => $shouldStartImmediately || $startApproved >= $required ? now() : null,
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

    /**
     * @return array{
     *     booking:CleaningBooking,
     *     extensionPricing:array{
     *         requestedMinutes:int,
     *         matchedRange:array{id:int,startMinutes:int,endMinutes:int,label:string},
     *         calculatedExtensionPrice:float,
     *         currency:string
     *     }
     * }
     */
    public function requestCompletionExtension(CleaningBooking $booking, int $additionalMinutes): array
    {
        $fromStatus = (string) $booking->status->value;

        if ($booking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
            throw ValidationException::withMessages([
                'status' => ['Order is not waiting for completion confirmation.'],
            ]);
        }

        $extensionPricing = $this->extendedTimePricing->quote($additionalMinutes);

        $updated = DB::transaction(function () use ($booking, $additionalMinutes, $extensionPricing): CleaningBooking {
            $booking = CleaningBooking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== CleaningBookingStatus::AwaitingCustomerCompletion) {
                throw ValidationException::withMessages([
                    'status' => ['Order is not waiting for completion confirmation.'],
                ]);
            }

            CleaningTimeWarning::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
                'worker_response' => null,
                'sent_at' => now(),
                'customer_responded_at' => now(),
                'worker_responded_at' => null,
                'additional_minutes' => $additionalMinutes,
                'quoted_amount' => $extensionPricing['calculatedExtensionPrice'],
                'quoted_currency' => $extensionPricing['currency'],
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

        return [
            'booking' => $updated,
            'extensionPricing' => $extensionPricing,
        ];
    }

    private function normalizedAssignmentMode(mixed $assignmentMode): ?string
    {
        if (! is_string($assignmentMode) || mb_trim($assignmentMode) === '') {
            return null;
        }

        return mb_strtolower(mb_trim($assignmentMode));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveAssignmentMode(array $validated, ?CleaningBooking $booking = null): string
    {
        $explicitMode = $this->normalizedAssignmentMode($validated['assignmentMode'] ?? null);
        $preferredWorkerId = array_key_exists('preferredWorkerId', $validated)
            ? $validated['preferredWorkerId']
            : $booking?->preferred_worker_id;
        $numberOfWorkers = array_key_exists('numberOfWorkers', $validated)
            ? (int) $validated['numberOfWorkers']
            : (int) ($booking?->number_of_workers ?? 1);

        if ($explicitMode === 'open_count') {
            return 'open_count';
        }

        if ($preferredWorkerId !== null && $numberOfWorkers <= 1) {
            return 'preferred_worker';
        }

        if ($explicitMode === 'preferred_worker') {
            return 'open_count';
        }

        if ($explicitMode !== null) {
            return $explicitMode;
        }

        return 'open_count';
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function shouldUsePreferredWorkerPricing(array $validated, ?CleaningBooking $booking = null): bool
    {
        return $this->resolveAssignmentMode($validated, $booking) === 'preferred_worker';
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveRequestedWorkers(
        array $validated,
        string $propertyType,
        int $suggestedWorkers,
        ?string $assignmentMode = null
    ): int
    {
        $assignmentMode = $assignmentMode ?? $this->resolveAssignmentMode($validated);

        if (array_key_exists('numberOfWorkers', $validated) && $validated['numberOfWorkers'] !== null) {
            return max(1, (int) $validated['numberOfWorkers']);
        }

        if ($assignmentMode === 'preferred_worker') {
            return 1;
        }

        if ($this->estimationService->isEventAssistanceType($propertyType)) {
            return max(1, $suggestedWorkers);
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveRequestedWorkersForUpdate(CleaningBooking $booking, array $validated): int
    {
        if (array_key_exists('numberOfWorkers', $validated) && $validated['numberOfWorkers'] !== null) {
            return max(1, (int) $validated['numberOfWorkers']);
        }

        if ($this->resolveAssignmentMode($validated, $booking) === 'preferred_worker') {
            return 1;
        }

        return max(1, (int) ($booking->number_of_workers ?? 1));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $updates
     */
    private function resolvePreferredWorkerForPricing(array $validated, CleaningBooking $booking, array $updates): ?int
    {
        if ($this->shouldUsePreferredWorkerPricing($validated, $booking)) {
            $preferredWorkerId = $updates['preferred_worker_id'] ?? $validated['preferredWorkerId'] ?? $booking->preferred_worker_id;

            return $preferredWorkerId !== null ? (int) $preferredWorkerId : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @param  array<string, mixed>  $validated
     * @return array{basePrice: float, addonsTotal: float, travelFee: float, distanceKm: ?float, adminMargin: float, isPricingFinal: bool, totalPrice: float}
     */
    private function resolveStoredPricingForUpdate(array $pricing, array $validated, CleaningBooking $booking): array
    {
        if ($this->shouldUsePreferredWorkerPricing($validated, $booking)) {
            return [
                'basePrice' => (float) $pricing['basePrice'],
                'addonsTotal' => (float) $pricing['addonsTotal'],
                'travelFee' => (float) $pricing['travelFee'],
                'distanceKm' => $pricing['distanceKm'] !== null ? (float) $pricing['distanceKm'] : null,
                'adminMargin' => (float) $pricing['adminMargin'],
                'isPricingFinal' => (bool) $pricing['isPricingFinal'],
                'totalPrice' => (float) $pricing['totalPrice'],
            ];
        }

        return [
            'basePrice' => (float) $pricing['basePrice'],
            'addonsTotal' => (float) $pricing['addonsTotal'],
            'travelFee' => 0.0,
            'distanceKm' => null,
            'adminMargin' => 0.0,
            'isPricingFinal' => false,
            'totalPrice' => round(((float) $pricing['basePrice']) + ((float) $pricing['addonsTotal']), 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $workerRoomAssignments
     * @return array<int, array<string, mixed>>|null
     */
    private function plannedWorkerRoomAssignments(
        string $propertyType,
        array $propertyDetails,
        ?array $workerRoomAssignments,
        string $assignmentMode,
        int $numberOfWorkers,
        ?int $preferredWorkerId,
    ): ?array {
        if ($this->estimationService->isEventAssistanceType($propertyType) || $workerRoomAssignments === null) {
            return null;
        }

        $plan = WorkerRoomAssignmentPlanner::plan(
            $propertyDetails,
            $workerRoomAssignments,
            $assignmentMode,
            $numberOfWorkers,
            $preferredWorkerId,
        );

        if ($plan['errors'] !== []) {
            throw ValidationException::withMessages($plan['errors']);
        }

        return array_map(static fn (array $assignment): array => [
            'workerSlot' => $assignment['workerSlot'],
            'preferredWorkerId' => $assignment['preferredWorkerId'],
            'rooms' => array_map(static fn (array $room): array => [
                'roomKey' => $room['roomKey'],
                'roomType' => $room['roomType'],
                'roomSize' => $room['roomSize'],
            ], $assignment['rooms']),
        ], $plan['assignments']);
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
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  mixed  $services
     * @return array<int, string>|null
     */
    private function normalizeCleaningServices(mixed $services): ?array
    {
        if (! is_array($services)) {
            return null;
        }

        $normalized = [];

        foreach ($services as $service) {
            if (! is_string($service)) {
                continue;
            }

            $name = mb_trim($service);

            if ($name === '' || in_array($name, $normalized, true)) {
                continue;
            }

            $normalized[] = $name;
        }

        return $normalized !== [] ? $normalized : null;
    }
}
