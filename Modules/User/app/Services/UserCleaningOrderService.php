<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Enums\GenderPreference;
use App\Enums\WorkerCustomerRatingType;
use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use App\Support\Broadcast\BroadcastAfterResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Events\ArrivalVerified;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningBookingSpecialServiceItem;
use Modules\Cleaning\Models\CleaningEventType;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\Cleaning\Services\CleaningExtendedTimePricingService;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;
use Modules\Cleaning\Services\CleaningNeighborhoodResolver;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class UserCleaningOrderService
{
    private const MAX_SECURITY_CODE_ATTEMPTS = 5;

    private const PREFERRED_WORKER_NEIGHBORHOOD_MESSAGE = "\u{645}\u{642}\u{62f}\u{645} \u{627}\u{644}\u{62e}\u{62f}\u{645}\u{629} \u{627}\u{644}\u{645}\u{62e}\u{62a}\u{627}\u{631} \u{644}\u{627} \u{64a}\u{639}\u{645}\u{644} \u{636}\u{645}\u{646} \u{627}\u{644}\u{62d}\u{64a} \u{627}\u{644}\u{645}\u{62d}\u{62f}\u{62f}.";

    public function __construct(
        private UserCleaningOrderEstimationService $estimationService,
        private CleaningBookingTeamService $teamService,
        private CleaningLifecycleNotificationService $lifecycleNotifications,
        private CleaningExtendedTimePricingService $extendedTimePricing,
        private DepositService $depositService,
        private CleaningNeighborhoodResolver $neighborhoodResolver,
        private CleaningMaterialInventoryService $materialInventory,
    ) {}

    public function store(User $user, array $validated): CleaningBooking
    {
        return DB::transaction(function () use ($user, $validated): CleaningBooking {
            $isOpenTime = is_array($validated['openTime'] ?? null);
            $normalizedPropertyType = $this->estimationService->normalizePropertyType((string) $validated['propertyType']);
            $normalizedPropertyDetails = $this->estimationService->normalizePropertyDetailsForStorage($normalizedPropertyType, (array) $validated['propertyDetails']);
            $eventPayload = is_array($validated['event'] ?? null) ? $validated['event'] : [];
            $eventTypeId = (int) ($eventPayload['eventTypeId'] ?? $validated['propertyDetails']['eventTypeId'] ?? 0);
            $eventType = $eventTypeId > 0
                ? CleaningEventType::query()->where('is_active', true)->find($eventTypeId)
                : null;
            if ($eventType instanceof CleaningEventType) {
                $normalizedPropertyDetails['event_type'] = $eventType->slug;
            }
            $explicitWorkerScope = $this->explicitWorkerScope($validated);
            $specificWorkerIds = $this->normalizedSpecificWorkerIds($validated);
            $resolvedAssignmentMode = $this->resolveAssignmentMode($validated);
            $resolvedNeighborhood = $this->resolveNeighborhoodFromPayload($validated);
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
                $pricing = $isOpenTime
                    ? $this->estimationService->priceOpenTime(
                        $normalizedInput['propertyType'],
                        $normalizedInput['propertyDetails'],
                        $normalizedInput['addressLatitude'],
                        $normalizedInput['addressLongitude'],
                        $pricingPreferredWorkerId,
                        max(1, (int) ($validated['openTime']['workerCount'] ?? 1)),
                        max(15, (int) ($validated['openTime']['expectedMaxMinutes'] ?? 480)),
                    )
                    : $this->estimationService->price(
                        $normalizedInput['propertyType'],
                        $normalizedInput['propertyDetails'],
                        $normalizedInput['addressLatitude'],
                        $normalizedInput['addressLongitude'],
                        $pricingPreferredWorkerId,
                        null,
                        (bool) ($validated['requestMaterials'] ?? false),
                        is_array($validated['specialServices'] ?? null) ? $validated['specialServices'] : [],
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

            if ($explicitWorkerScope === CleaningBooking::WORKER_SCOPE_SPECIFIC) {
                $this->guardSpecificWorkerCoverage($specificWorkerIds, $resolvedNeighborhood);
            } elseif ($resolvedAssignmentMode === 'preferred_worker') {
                $this->guardPreferredWorkerCoverage(
                    $normalizedInput['preferredWorkerId'] !== null ? (int) $normalizedInput['preferredWorkerId'] : null,
                    $resolvedNeighborhood,
                );
            }

            $booking = CleaningBooking::create([
                'customer_id' => $user->id,
                'worker_id' => null,
                'preferred_worker_id' => $resolvedAssignmentMode === 'preferred_worker'
                    ? $normalizedInput['preferredWorkerId']
                    : null,
                'assignment_mode' => $resolvedAssignmentMode,
                'worker_scope' => $explicitWorkerScope,
                'specific_worker_ids' => $explicitWorkerScope === CleaningBooking::WORKER_SCOPE_SPECIFIC
                    ? $specificWorkerIds
                    : null,
                'number_of_workers' => $requestedWorkers,
                'gender_preference' => $validated['genderPreference'] ?? GenderPreference::Any->value,
                'cancellation_policy_id' => $validated['cancellationPolicyId'] ?? $this->defaultCancellationPolicyId(),
                'billing_policy_id' => $validated['billingPolicyId'] ?? $this->defaultBillingPolicyId(),
                'booking_number' => $this->generateBookingNumber(),
                'status' => CleaningBookingStatus::Pending,
                'booking_kind' => $isOpenTime
                    ? 'open_time'
                    : ((string) ($validated['bookingKind'] ?? '') === 'special_service' ? 'special_service' : 'standard'),
                'capability_schema_version' => 2,
                'property_type' => $normalizedPropertyType,
                'cleaning_event_type_id' => $eventType?->id,
                'property_details' => $normalizedPropertyDetails,
                'event_dynamic_answers' => (array) ($eventPayload['dynamicAnswers'] ?? $validated['propertyDetails']['dynamicAnswers'] ?? []),
                'cleaning_services' => $this->normalizeCleaningServices($validated['cleaning_services'] ?? null),
                'address_latitude' => $normalizedInput['addressLatitude'],
                'address_longitude' => $normalizedInput['addressLongitude'],
                'neighborhood_id' => $resolvedNeighborhood?->id,
                'neighborhood_name' => $resolvedNeighborhood?->name_ar ?? ($validated['neighborhood'] ?? null),
                'estimated_sqm' => $estimation['estimatedSqm'],
                'estimated_hours' => $estimation['estimatedHours'],
                'scheduled_date' => $validated['scheduledDate'],
                'scheduled_time' => $validated['scheduledTime'],
                'total_hours' => $estimation['estimatedHours'],
                'base_price' => $storedPricing['basePrice'],
                'open_time_hourly_rate' => $isOpenTime ? ($pricing['openTime']['hourlyRate'] ?? null) : null,
                'open_time_minimum_minutes' => $isOpenTime ? ($pricing['openTime']['minimumBillableMinutes'] ?? null) : null,
                'open_time_rounding_minutes' => $isOpenTime ? ($pricing['openTime']['roundingMinutes'] ?? null) : null,
                'open_time_expected_max_minutes' => $isOpenTime ? ($pricing['openTime']['expectedMaxMinutes'] ?? 480) : null,
                'open_time_hard_max_minutes' => $isOpenTime ? ($pricing['openTime']['hardMaxMinutes'] ?? 480) : null,
                'open_time_warning_minutes' => $isOpenTime ? ($pricing['openTime']['warningMinutes'] ?? 30) : null,
                'open_time_extension_options' => $isOpenTime ? ($pricing['openTime']['extensionOptions'] ?? [15, 30, 60]) : null,
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
            $this->persistNewServiceLines($booking, $pricing);

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
                'neighborhoodId',
                'neighborhood',
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
            $pricing = null;
            $resolvedNeighborhood = $this->resolveNeighborhoodForUpdate($booking, $validated);

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

            if (array_key_exists('neighborhoodId', $validated) || array_key_exists('neighborhood', $validated)) {
                $updates['neighborhood_id'] = $resolvedNeighborhood?->id;
                $updates['neighborhood_name'] = $resolvedNeighborhood?->name_ar ?? ($validated['neighborhood'] ?? null);
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

            if (array_key_exists('requestMaterials', $validated) || array_key_exists('specialServices', $validated)) {
                $pricingFieldsChanged = true;
            }

            if ($booking->booking_kind === 'open_time' && $pricingFieldsChanged) {
                throw ValidationException::withMessages([
                    'order' => ['Open-Time pricing inputs cannot be edited after the booking is created.'],
                ]);
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
                    $requestMaterials = array_key_exists('requestMaterials', $validated)
                        ? (bool) $validated['requestMaterials']
                        : $booking->materials()->exists();
                    $specialServices = array_key_exists('specialServices', $validated)
                        ? (array) $validated['specialServices']
                        : $this->existingSpecialServiceRequests($booking);
                    $pricing = $this->estimationService->price(
                        $normalizedInput['propertyType'],
                        $normalizedInput['propertyDetails'],
                        $normalizedInput['addressLatitude'],
                        $normalizedInput['addressLongitude'],
                        $normalizedInput['preferredWorkerId'],
                        null,
                        $requestMaterials,
                        $specialServices,
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

            $effectiveAssignmentMode = (string) ($updates['assignment_mode'] ?? $booking->resolvedAssignmentMode());
            $effectivePreferredWorkerId = array_key_exists('preferred_worker_id', $updates)
                ? $updates['preferred_worker_id']
                : $booking->preferred_worker_id;

            if ($effectiveAssignmentMode === 'preferred_worker') {
                $this->guardPreferredWorkerCoverage(
                    $effectivePreferredWorkerId !== null ? (int) $effectivePreferredWorkerId : null,
                    $resolvedNeighborhood,
                );
            }

            if ($updates !== []) {
                $booking->update($updates);
            }

            if (is_array($pricing)) {
                $shouldReplaceMaterials = array_key_exists('requestMaterials', $validated)
                    || array_key_exists('propertyDetails', $validated)
                    || array_key_exists('propertyType', $validated);
                $shouldReplaceSpecialServices = array_key_exists('specialServices', $validated);
                if ($shouldReplaceMaterials || $shouldReplaceSpecialServices) {
                    $this->replaceNewServiceLines(
                        $booking,
                        $pricing,
                        $shouldReplaceMaterials,
                        $shouldReplaceSpecialServices,
                    );
                }
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

        if (! in_array($booking->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned, CleaningBookingStatus::AwaitingStartVerification], true)) {
            throw ValidationException::withMessages([
                'order' => ['Order cannot be cancelled in current status.'],
            ]);
        }

        $booking->update([
            'status' => CleaningBookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
        $this->materialInventory->releaseForBooking($booking);

        // TODO: add tag to order that cancelation is from the user if the CleaningBookingStatus::AwaitingStartVerification
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
        if (! in_array($booking->status, [
            CleaningBookingStatus::AwaitingStartVerification,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation,
        ], true)) {
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

            $booking->update([
                'status' => CleaningBookingStatus::AwaitingWorkerStartConfirmation,
                'work_started_at' => null,
                'customer_confirmed_at' => now(),
            ]);

            $updated = $booking->fresh();

            $this->dispatchTrackingUpdate($updated);
            BroadcastAfterResponse::send(new ArrivalVerified(
                $updated->id,
                $updated->worker_id,
                (string) $updated->arrived_at?->toIso8601String(),
                (string) $updated->status?->value,
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

        $updated = DB::transaction(function () use ($booking): CleaningBooking {
            $booking->update([
                'status' => CleaningBookingStatus::Completed,
                'customer_confirmed_at' => now(),
            ]);

            $freshBooking = $booking->fresh([
                'workerAssignments.worker',
            ]);

            if ($freshBooking === null) {
                return $booking;
            }

            foreach ($freshBooking->workerAssignments as $assignment) {
                $status = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
                    ? $assignment->status->value
                    : (string) $assignment->status;

                if (! in_array($status, CleaningBookingWorkerAssignmentStatus::acceptedValues(), true)) {
                    continue;
                }

                $worker = $assignment->worker;
                $adminFee = (float) $assignment->admin_margin_amount;

                if ($worker instanceof Worker && $adminFee > 0) {
                    $this->depositService->recordAdminFeeDebit($worker, $freshBooking, $adminFee);
                }
            }

            return $freshBooking;
        });

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

        $extensionPricing = $this->extendedTimePricing->quoteForBooking($booking, $additionalMinutes);

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
                'quoted_base_amount' => $extensionPricing['baseAmount'],
                'quoted_admin_margin_amount' => $extensionPricing['adminMargin'],
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

    /**
     * @param  array{workerId:int,rating:int,comment?:string|null}  $validated
     */
    public function submitReview(CleaningBooking $booking, array $validated): WorkerCustomerRating
    {
        $workerId = (int) $validated['workerId'];
        $completedAssignmentExists = $booking->workerAssignments()
            ->where('worker_id', $workerId)
            ->where('status', CleaningBookingWorkerAssignmentStatus::Completed->value)
            ->exists();
        $legacyBookingWorkerCompleted = (int) $booking->worker_id === $workerId
            && $booking->status === CleaningBookingStatus::Completed;

        if (! $completedAssignmentExists && ! $legacyBookingWorkerCompleted) {
            throw ValidationException::withMessages([
                'workerId' => ['Review can only be submitted for a worker whose work has been confirmed.'],
            ]);
        }

        /** @var WorkerCustomerRating $review */
        $review = WorkerCustomerRating::query()->updateOrCreate(
            [
                'booking_id' => $booking->id,
                'booking_type' => $booking->getMorphClass(),
                'worker_id' => $workerId,
                'customer_id' => $booking->customer_id,
                'rating_type' => WorkerCustomerRatingType::CustomerToWorker->value,
            ],
            [
                'rating' => (int) $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]
        );

        $this->syncWorkerAverageRating($workerId);

        return $review;
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function persistNewServiceLines(CleaningBooking $booking, array $pricing): void
    {
        $this->persistMaterialLines($booking, (array) ($pricing['materials'] ?? []));
        $this->persistSpecialServiceLines($booking, (array) ($pricing['specialServices'] ?? []));
    }

    /** @param array<int, mixed> $lines */
    private function persistMaterialLines(CleaningBooking $booking, array $lines): void
    {
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $this->materialInventory->reserveTypeLine($booking, $line);
        }

    }

    /** @param array<int, mixed> $lines */
    private function persistSpecialServiceLines(CleaningBooking $booking, array $lines): void
    {
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $bookingService = CleaningBookingSpecialService::query()->create([
                'cleaning_booking_id' => $booking->id,
                'cleaning_special_service_id' => (int) $line['specialServiceId'],
                'service_name' => (string) $line['name'],
                'pricing_unit' => (string) $line['pricingUnit'],
                'dirtiness_level' => (string) $line['dirtinessLevel'],
                'quantity' => (float) $line['quantity'],
                'base_unit_price' => (float) $line['baseUnitPrice'],
                'price_multiplier' => (float) $line['priceMultiplier'],
                'total_price' => (float) $line['totalPrice'],
                'equipment_snapshot' => (array) ($line['equipment'] ?? []),
                'notes' => $line['notes'] ?? null,
                'execution_status' => 'pending',
                'financial_snapshot' => (array) ($line['financialSnapshot'] ?? []),
            ]);

            foreach ((array) ($line['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                CleaningBookingSpecialServiceItem::query()->create([
                    'cleaning_booking_special_service_id' => $bookingService->id,
                    'cleaning_dirtiness_level_id' => $item['dirtinessLevelId'] ?? null,
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'dirtiness_level' => $item['dirtinessLevel'] ?? null,
                    'price_multiplier' => (float) ($item['priceMultiplier'] ?? 1),
                    'base_unit_price' => (float) ($item['baseUnitPrice'] ?? 0),
                    'total_price' => (float) ($item['totalPrice'] ?? 0),
                    'notes' => $item['notes'] ?? null,
                    'before_images' => (array) ($item['beforeImages'] ?? []),
                    'after_images' => (array) ($item['afterImages'] ?? []),
                ]);
            }
        }
    }

    /**
     * Link requested special services to materialized recurring/event sessions.
     * Session identifiers in a create payload are session sequence numbers; on
     * update, persisted session ids are also accepted.
     *
     * @param array<int,mixed> $requestedLines
     */
    public function syncSpecialServiceSessions(CleaningBooking $booking, array $requestedLines): void
    {
        $requestedByService = [];
        foreach ($requestedLines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $serviceId = (int) ($line['serviceId'] ?? $line['specialServiceId'] ?? 0);
            $requestedByService[$serviceId] = array_values(array_unique(array_filter(
                array_map('intval', (array) ($line['sessionIds'] ?? [])),
                static fn (int $id): bool => $id > 0,
            )));
        }

        $sessions = $booking->sessions()->get(['id', 'sequence']);
        foreach ($booking->specialServices()->get() as $bookingService) {
            $requested = $requestedByService[(int) $bookingService->cleaning_special_service_id] ?? [];
            if ($requested === []) {
                continue;
            }
            $ids = $sessions
                ->filter(static fn ($session): bool => in_array((int) $session->id, $requested, true)
                    || in_array((int) $session->sequence, $requested, true))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            if ($ids === []) {
                continue;
            }

            $sourceItems = $bookingService->items()->get();
            foreach ($ids as $index => $sessionId) {
                $line = $index === 0 ? $bookingService : $bookingService->replicate();
                $line->cleaning_booking_session_id = $sessionId;
                $line->execution_status = 'pending';
                $line->assigned_worker_id = null;
                $line->started_at = null;
                $line->completed_at = null;
                $line->save();
                $line->sessions()->sync([$sessionId]);

                if ($index > 0) {
                    foreach ($sourceItems as $sourceItem) {
                        $item = $sourceItem->replicate();
                        $item->cleaning_booking_special_service_id = $line->id;
                        $item->save();
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function replaceNewServiceLines(
        CleaningBooking $booking,
        array $pricing,
        bool $replaceMaterials,
        bool $replaceSpecialServices,
    ): void {
        if ($replaceMaterials) {
            $this->materialInventory->releaseForBooking($booking);
            $booking->materials()->delete();
            $this->persistMaterialLines($booking, (array) ($pricing['materials'] ?? []));
        }

        if ($replaceSpecialServices) {
            $booking->specialServices()->delete();
            $this->persistSpecialServiceLines($booking, (array) ($pricing['specialServices'] ?? []));
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function existingSpecialServiceRequests(CleaningBooking $booking): array
    {
        return $booking->specialServices()
            ->get()
            ->map(static fn (CleaningBookingSpecialService $line): array => [
                'specialServiceId' => (int) $line->cleaning_special_service_id,
                'quantity' => (float) $line->quantity,
                'dirtinessLevel' => $line->dirtiness_level,
                'notes' => $line->notes,
            ])
            ->all();
    }

    private function syncWorkerAverageRating(int $workerId): void
    {
        $average = WorkerCustomerRating::query()
            ->where('worker_id', $workerId)
            ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
            ->avg('rating');

        Worker::query()->whereKey($workerId)->update([
            'average_rating' => $average !== null ? round((float) $average, 2) : 0,
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function explicitWorkerScope(array $validated): ?string
    {
        if (! array_key_exists('workerScope', $validated) || ! is_string($validated['workerScope'])) {
            return null;
        }

        $scope = mb_strtolower(mb_trim($validated['workerScope']));

        return in_array($scope, [CleaningBooking::WORKER_SCOPE_ANY, CleaningBooking::WORKER_SCOPE_SPECIFIC], true)
            ? $scope
            : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function normalizedSpecificWorkerIds(array $validated): array
    {
        $ids = [];
        foreach (is_array($validated['preferredWorkerIds'] ?? null) ? $validated['preferredWorkerIds'] : [] as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
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
    ): int {
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
     * @param  array<string, mixed>  $validated
     */
    private function resolveNeighborhoodFromPayload(array $validated): ?CleaningNeighborhood
    {
        $neighborhoodId = array_key_exists('neighborhoodId', $validated) && $validated['neighborhoodId'] !== null
            ? (int) $validated['neighborhoodId']
            : null;
        $neighborhoodName = array_key_exists('neighborhood', $validated) && is_string($validated['neighborhood'])
            ? $validated['neighborhood']
            : null;

        return $this->neighborhoodResolver->resolve($neighborhoodId, $neighborhoodName);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveNeighborhoodForUpdate(CleaningBooking $booking, array $validated): ?CleaningNeighborhood
    {
        if (array_key_exists('neighborhoodId', $validated) || array_key_exists('neighborhood', $validated)) {
            return $this->resolveNeighborhoodFromPayload($validated);
        }

        if ($booking->neighborhood_id !== null) {
            return $this->neighborhoodResolver->findById((int) $booking->neighborhood_id, activeOnly: false);
        }

        if ($booking->neighborhood_name !== null && mb_trim((string) $booking->neighborhood_name) !== '') {
            return $this->neighborhoodResolver->resolve(null, (string) $booking->neighborhood_name, activeOnly: false);
        }

        return null;
    }

    /** @param array<int, int> $specificWorkerIds */
    private function guardSpecificWorkerCoverage(array $specificWorkerIds, ?CleaningNeighborhood $neighborhood): void
    {
        if ($specificWorkerIds === [] || $neighborhood === null) {
            return;
        }

        $coveredIds = Worker::query()
            ->whereIn('id', $specificWorkerIds)
            ->coversNeighborhood((int) $neighborhood->id)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $missing = array_values(array_diff($specificWorkerIds, $coveredIds));
        if ($missing === []) {
            return;
        }

        throw ValidationException::withMessages([
            'preferredWorkerIds' => [self::PREFERRED_WORKER_NEIGHBORHOOD_MESSAGE],
        ]);
    }

    private function guardPreferredWorkerCoverage(?int $preferredWorkerId, ?CleaningNeighborhood $neighborhood): void
    {
        if ($preferredWorkerId === null || $neighborhood === null) {
            return;
        }

        $worker = Worker::query()
            ->with('zones')
            ->find($preferredWorkerId);

        if ($worker?->hasActiveCoverageForNeighborhood((int) $neighborhood->id)) {
            return;
        }

        throw ValidationException::withMessages([
            'preferredWorkerId' => [self::PREFERRED_WORKER_NEIGHBORHOOD_MESSAGE],
        ]);
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
