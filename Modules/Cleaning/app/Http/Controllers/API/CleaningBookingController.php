<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SOSStatus;
use App\Enums\SystemAlertStatus;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Data\CleaningBookingData;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Requests\CleaningBookingAcceptRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingCancelRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingCompleteRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingSosRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingLocationRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingRoomClaimRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingRejectRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingRequest;
use Modules\Cleaning\Http\Requests\CleaningBookingRequests\CleaningBookingFilterRequest;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingService;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;
use Modules\User\Http\Resources\UserCleaningSosResource;
use Throwable;

final class CleaningBookingController
{
    public function __construct(
        private CleaningBookingService $cleaningBookingService,
        private readonly DepositService $depositService,
        private readonly WorkerOrderSolvencyService $solvencyService,
    ) {}

    public function index(CleaningBookingFilterRequest $request): AnonymousResourceCollection|JsonResponse
    {
        // A worker cannot bypass the geographic dispatch audience by omitting
        // filter[forCurrentWorker] from the request.
        $query = CleaningBooking::getQuery();
        $authenticatedWorker = $request->user()?->worker;
        if ($authenticatedWorker instanceof Worker) {
            $query->forCurrentWorker(true);
            // The fourth service is never an open invitation. Only assigned
            // workers may discover a supervisor-managed booking.
            $query->where(function ($visible) use ($authenticatedWorker): void {
                $visible->where(function ($legacy): void {
                    $legacy->whereNull('booking_kind')
                        ->orWhere('booking_kind', '!=', 'supervised_team');
                })->orWhereHas('workerAssignments', function ($assignments) use ($authenticatedWorker): void {
                    $assignments->where('worker_id', $authenticatedWorker->id)
                        ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                });
            });
        } else {
            $this->assertGenericOperationsActor($request->user());
        }

        $relations = [
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'addons',
                'billingPolicy',
                'timeWarnings',
                'disputes',
        ];
        if (! $authenticatedWorker instanceof Worker) {
            $relations[] = 'customer';
        }

        $bookings = $query
            ->with($relations)
            ->paginate($request->get('perPage', 20));

        $worker = $request->user()?->worker;

        if ($worker instanceof Worker) {
            $worker->loadMissing('deposit');
            $this->filterFinanciallyBlockedPendingBookings($bookings, $worker);
            $this->filterV2BookingsWithoutAcceptedVisitProof($bookings, $worker);

            return response()->json([
                'data' => $bookings->getCollection()
                    ->map(fn (CleaningBooking $booking): array => $this->workerDiscoveryPayload($booking, $request, $worker))
                    ->values()
                    ->all(),
                'links' => [
                    'first' => $bookings->url(1),
                    'last' => $bookings->url($bookings->lastPage()),
                    'prev' => $bookings->previousPageUrl(),
                    'next' => $bookings->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $bookings->currentPage(),
                    'from' => $bookings->firstItem(),
                    'last_page' => $bookings->lastPage(),
                    'path' => $bookings->path(),
                    'per_page' => $bookings->perPage(),
                    'to' => $bookings->lastItem(),
                    'total' => $bookings->total(),
                ],
                'dispatchEligibility' => $this->newRequestEligibility(
                    $worker,
                    $this->depositService->depositStatusPayload($worker),
                ),
            ]);
        }

        $collection = CleaningBookingResource::collection($bookings);

        return $collection;
    }

    /** @throws Throwable */
    public function store(CleaningBookingRequest $request): JsonResponse
    {
        $booking = $this->cleaningBookingService->store(
            CleaningBookingData::from($request->validated())
        );

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        )->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(CleaningBooking $cleaning_booking): CleaningBookingResource
    {
        return CleaningBookingResource::make($this->loadBookingDetails($cleaning_booking));
    }

    public function securityCode(CleaningBooking $cleaning_booking): JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        if (! in_array($cleaning_booking->status, [
            CleaningBookingStatus::WorkerAssigned,
            CleaningBookingStatus::AwaitingStartVerification,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Security code is only available for bookings ready to start.'],
            ]);
        }

        try {
            $generated = $this->cleaningBookingService->issueSecurityCode($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'status' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'message' => __('Security code generated successfully.'),
            'data' => [
                'securityCode' => $generated['securityCode'],
                'expiresAt' => $generated['expiresAt'],
            ],
        ]);
    }

    public function sos(CleaningBookingSosRequest $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        $status = $cleaning_booking->status instanceof CleaningBookingStatus
            ? $cleaning_booking->status->value
            : (string) $cleaning_booking->status;

        if (in_array($status, [CleaningBookingStatus::Completed->value, CleaningBookingStatus::Cancelled->value], true)) {
            throw ValidationException::withMessages([
                'cleaning_booking' => ['Cannot create an SOS request for a completed or cancelled cleaning booking.'],
            ]);
        }

        $userId = (int) $request->user()->id;

        $activeSos = SosAlert::query()
            ->where('user_id', $userId)
            ->where('booking_id', $cleaning_booking->id)
            ->where('booking_type', CleaningBooking::class)
            ->whereIn('status', [SOSStatus::Triggered->value, SOSStatus::Acknowledged->value])
            ->latest('id')
            ->first();

        if ($activeSos instanceof SosAlert) {
            return response()->json([
                'success' => true,
                'message' => 'Cleaning booking SOS request already exists.',
                'data' => UserCleaningSosResource::make($activeSos)->resolve($request),
            ]);
        }

        $sos = DB::transaction(function () use ($request, $cleaning_booking): SosAlert {
            $sos = SosAlert::query()->create([
                'user_id' => $request->user()?->id,
                'booking_id' => $cleaning_booking->id,
                'booking_type' => CleaningBooking::class,
                'emergency_type' => $request->validated('emergency_type'),
                'message' => $request->validated('message'),
                'source' => 'booking',
                'status' => SOSStatus::Triggered->value,
                'latitude' => $request->validated('latitude'),
                'longitude' => $request->validated('longitude'),
                'triggered_at' => now(),
            ]);

            SystemAlert::query()->create([
                'booking_id' => $cleaning_booking->id,
                'booking_type' => CleaningBooking::class,
                'alert_type' => AlertType::SOSTriggered->value,
                'severity' => AlertSeverity::Critical->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => [
                    'source' => 'cleaning_worker_sos',
                    'sos_alert_id' => $sos->id,
                    'user_id' => $request->user()?->id,
                    'booking_id' => $cleaning_booking->id,
                    'message' => $request->validated('message'),
                    'emergency_type' => $request->validated('emergency_type'),
                    'latitude' => $request->validated('latitude'),
                    'longitude' => $request->validated('longitude'),
                ],
            ]);

            return $sos;
        });

        return response()->json([
            'success' => true,
            'message' => 'Cleaning booking SOS request sent successfully.',
            'data' => UserCleaningSosResource::make($sos)->resolve($request),
        ], 201);
    }

    /** @throws Throwable */
    public function update(CleaningBookingRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource
    {
        $updated = $this->cleaningBookingService->update(
            $request->validated(),
            $cleaning_booking
        );

        return CleaningBookingResource::make(
            $this->loadBookingDetails($updated)
        );
    }

    public function destroy(CleaningBooking $cleaning_booking): Response
    {
        $this->assertGenericOperationsActor(Auth::user());
        $this->cleaningBookingService->destroyGeneric($cleaning_booking);

        return response()->noContent();
    }

    /** @throws Throwable */
    public function accept(CleaningBookingAcceptRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: false);
        $eligibilityResponse = $this->blockedAcceptResponse($cleaning_booking, $request->validated('roomIds'));

        if ($eligibilityResponse instanceof JsonResponse) {
            return $eligibilityResponse;
        }

        try {
            $booking = $this->cleaningBookingService->accept($cleaning_booking, $request->validated('roomIds'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
    }

    /** @throws Throwable */
    public function claimRooms(CleaningBookingRoomClaimRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        abort_if($cleaning_booking->isSupervisedTeam(), 403, 'لا يوجد توزيع رقمي للغرف ضمن فريق المشرف.');
        try {
            $booking = $this->cleaningBookingService->claimRooms($cleaning_booking, $request->validated('roomIds'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
    }

    /** @throws Throwable */
    public function reject(CleaningBookingRejectRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: false);

        try {
            $booking = $this->cleaningBookingService->reject($cleaning_booking, $request->validated('reason'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
    }

    /** @throws Throwable */
    public function startTravel(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->startTravel($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
    }

    public function updateLocation(CleaningBookingLocationRequest $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $this->cleaningBookingService->updateLocation(
                $cleaning_booking,
                (float) $request->validated('latitude'),
                (float) $request->validated('longitude'),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /** @throws Throwable */
    public function arrive(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->arrive($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
    }

    /** @throws Throwable */
    public function startWork(CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->startWork($cleaning_booking);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
    }

    /** @throws Throwable */
    public function complete(CleaningBookingCompleteRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->complete(
                $cleaning_booking,
                $request->completionMessage(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
    }

    /** @throws Throwable */
    public function cancel(CleaningBookingCancelRequest $request, CleaningBooking $cleaning_booking): CleaningBookingResource|JsonResponse
    {
        $this->ensureWorkerCanActOnBooking($cleaning_booking, requireOwnership: true);

        try {
            $booking = $this->cleaningBookingService->cancel($cleaning_booking, $request->validated('reason'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        return CleaningBookingResource::make(
            $this->loadBookingDetails($booking)
        );
    }

    private function ensureWorkerCanActOnBooking(CleaningBooking $booking, bool $requireOwnership = true): void
    {
        $worker = Auth::user()?->worker;

        if (! $worker) {
            abort(403, 'User must have an associated worker.');
        }

        $hasWorkerAssignment = $booking->workerAssignments()
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if ($booking->worker_id !== null && $booking->worker_id !== $worker->id && ! $hasWorkerAssignment) {
            abort(403, 'Booking is assigned to another worker.');
        }

        if ($requireOwnership && $booking->worker_id === null && ! $hasWorkerAssignment) {
            abort(403, 'Booking must be assigned to worker for this action.');
        }
    }

    private function loadBookingDetails(CleaningBooking $booking): CleaningBooking
    {
        return $booking->load([
            'customer',
            'worker.user',
            'preferredWorker.user',
            'rooms.assignedWorker.user',
            'workerAssignments.worker.user',
            'addons',
            'billingPolicy',
            'timeWarnings',
            'disputes',
        ]);
    }

    /** @param array<int, mixed>|null $roomIds */
    private function blockedAcceptResponse(CleaningBooking $booking, ?array $roomIds = null): ?JsonResponse
    {
        $worker = Auth::user()?->worker;

        if (! $worker instanceof Worker) {
            return null;
        }

        $worker->loadMissing('deposit');
        $depositSummary = $this->depositService->depositStatusPayload($worker);
        $eligibility = $this->newRequestEligibility($worker, $depositSummary);
        $solvency = $this->solvencyService->solvencyPayloadForBooking($worker, $booking, $roomIds);
        $eligibility['bookingSolvency'] = $solvency;

        if ((bool) $eligibility['canAcceptNewBookings'] && (bool) $solvency['canAcceptBooking']) {
            return null;
        }

        $reasonCode = (bool) $eligibility['canAcceptNewBookings'] ? (string) $solvency['reasonCode'] : (string) $eligibility['reasonCode'];
        $message = (bool) $eligibility['canAcceptNewBookings']
            ? 'The available deposit or remaining allowance does not cover this booking platform commission.'
            : $eligibility['message'];

        return response()->json([
            'message' => $message,
            'code' => 'WORKER_NOT_ELIGIBLE_FOR_BOOKING_COMMISSION',
            'errors' => [
                'workerEligibility' => [
                    [
                        'code' => 'WORKER_NOT_ELIGIBLE_FOR_BOOKING_COMMISSION',
                        'reasonCode' => $reasonCode,
                        'message' => $message,
                        'dispatchEligibility' => $eligibility,
                        'bookingSolvency' => $solvency,
                    ],
                ],
            ],
            'dispatchEligibility' => $eligibility,
            'bookingSolvency' => $solvency,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param  array<string, mixed>  $depositSummary
     * @return array<string, mixed>
     */
    private function newRequestEligibility(Worker $worker, array $depositSummary): array
    {
        $canReceive = (bool) ($depositSummary['isEligibleForNewRequests'] ?? false);
        $reasonCode = $this->eligibilityReasonCode($worker, $depositSummary, $canReceive);

        return [
            'canReceiveNewRequests' => $canReceive,
            'canAcceptNewBookings' => $canReceive,
            'reasonCode' => $reasonCode,
            'message' => $this->eligibilityMessage($reasonCode),
            'depositSummary' => array_merge($depositSummary, $this->solvencyService->workerCapacitySummary($worker)),
        ];
    }

    /** @param array<string, mixed> $depositSummary */
    private function eligibilityReasonCode(Worker $worker, array $depositSummary, bool $canReceive): string
    {
        if (! $worker->is_active) {
            return 'worker_inactive';
        }

        if ($worker->is_suspended) {
            return 'worker_suspended';
        }

        if ($canReceive) {
            return 'eligible';
        }

        if ((bool) ($depositSummary['isAllowanceLimitExhausted'] ?? false)) {
            return 'allowance_limit_exhausted';
        }

        if (($depositSummary['exceedanceAmount'] ?? null) !== null) {
            return 'deposit_below_allowed_balance';
        }

        $currentBalance = (float) ($depositSummary['currentBalance'] ?? 0);
        $minimumRequired = (float) ($depositSummary['minimumRequired'] ?? 0);
        if ($currentBalance > 0 && $minimumRequired > 0 && $currentBalance < $minimumRequired) {
            return 'deposit_required_before_start';
        }

        return 'trust_score_too_low';
    }

    private function eligibilityMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'eligible' => 'Your account can receive and accept new requests.',
            'worker_inactive' => 'Your account is inactive. Reactivate your account to receive new requests.',
            'worker_suspended' => 'Your account is suspended. Please contact support for more details.',
            'allowance_limit_exhausted' => 'Your allowance limit has reached zero. Settle the administration margin before receiving new requests.',
            'deposit_below_allowed_balance' => 'Your indebtedness exceeds the worker allowance limit. Settle the outstanding amount before receiving new requests.',
            'deposit_required_before_start' => 'Your deposit balance is below the minimum required amount.',
            'trust_score_too_low' => 'Your trust score is below the minimum required to receive new requests.',
            default => 'Your account cannot receive new requests right now.',
        };
    }

    private function filterFinanciallyBlockedPendingBookings(LengthAwarePaginator $bookings, Worker $worker): void
    {
        $bookings->setCollection($bookings->getCollection()
            ->filter(function (CleaningBooking $booking) use ($worker): bool {
                if (! $this->isPendingCandidateForWorker($booking, $worker)) {
                    return true;
                }

                return $this->solvencyService->canWorkerReceiveBooking($worker, $booking);
            })
            ->values());
    }

    private function filterV2BookingsWithoutAcceptedVisitProof(LengthAwarePaginator $bookings, Worker $worker): void
    {
        $bookings->setCollection($bookings->getCollection()
            ->filter(function (CleaningBooking $booking) use ($worker): bool {
                if (! $this->shouldScopeV2WorkerRooms($booking)) {
                    return true;
                }
                if ($this->isPendingCandidateForWorker($booking, $worker)) {
                    return true;
                }

                return $booking->sessions()
                    ->whereHas('workerAssignments', function ($assignments) use ($worker): void {
                        $assignments->where('worker_id', $worker->id)
                            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                    })
                    ->exists();
            })
            ->values());
    }

    private function isPendingCandidateForWorker(CleaningBooking $booking, Worker $worker): bool
    {
        if ($booking->status !== CleaningBookingStatus::Pending) {
            return false;
        }

        $hasAcceptedAssignment = $booking->workerAssignments->contains(
            fn ($assignment): bool => (int) $assignment->worker_id === (int) $worker->id
                && in_array($assignment->status, CleaningBookingWorkerAssignmentStatus::acceptedStatuses(), true)
        );

        if ($hasAcceptedAssignment) {
            return false;
        }

        return $booking->worker_id === null;
    }

    private function assertGenericOperationsActor(mixed $user): User
    {
        abort_unless($user instanceof User && $user->exists, 403);
        $actor = User::query()->whereKey($user->getKey())->where('is_active', true)->first();
        abort_unless($actor instanceof User
            && $actor->hasAnyRole(['admin', 'Super Admin', 'Cleaning Ops Manager']), 403);

        return $actor;
    }

    private function workerDiscoveryPayload(
        CleaningBooking $booking,
        CleaningBookingFilterRequest $request,
        Worker $worker,
    ): array
    {
        $payload = CleaningBookingResource::make($booking)->resolve($request);
        $safe = Arr::only($payload, [
            'id', 'bookingNumber', 'displayTitle', 'status', 'statusLabel', 'globalStatus',
            'worker_order_status', 'worker_order_status_label', 'type', 'bookingKind',
            'numberOfWorkers', 'required_workers_count', 'accepted_workers_count', 'pending_workers_count',
            'genderPreference', 'propertyType', 'propertyTypeLabel', 'numberOfRooms', 'numberOfKitchens',
            'numberOfBalconies', 'numberOfSheds', 'estimatedHours', 'scheduledDate', 'scheduledTime',
            'totalHours', 'workerTotalHours', 'serviceShareAmount', 'travelFee', 'workerAmount',
            'workerOffer', 'worker_offer', 'myAssignment', 'worker_assignment', 'isHotOrder',
            'urgencyLabel', 'createdAt', 'updatedAt',
        ]);

        $safe['visits'] = $this->workerVisitProjection($booking, $worker);
        $ownedRooms = [];
        if ($safe['visits'] !== []) {
            $ownedRooms = $this->workerOwnedRoomProjection($booking, $worker);
            $safe['address'] = $payload['address'] ?? null;
            $safe['addressLatitude'] = $payload['addressLatitude'] ?? null;
            $safe['addressLongitude'] = $payload['addressLongitude'] ?? null;
            $safe['roomAssignments'] = $ownedRooms;
        } elseif ((int) $booking->worker_id === (int) $worker->id
            && (int) $booking->number_of_workers === 1
            && (string) $booking->booking_kind !== 'supervised_team'
            && ! $booking->isV2OperationalGroup()
            && ! array_key_exists('_time_period_v2', (array) ($booking->property_details ?? []))
            && ! array_key_exists('_room_period_v2_approved_total', (array) ($booking->property_details ?? []))
            && ! $this->shouldScopeV2WorkerRooms($booking)
            && ! $booking->sessions()->exists()) {
            // Pre-session legacy single-worker bookings have no visit proof.
            // Restore only the worker's own room identities here; never turn
            // this compatibility path into V2 group room or address discovery.
            $safe['roomAssignments'] = collect($payload['roomAssignments'] ?? [])
                ->filter(static fn (mixed $room): bool => is_array($room)
                    && (int) ($room['assignedWorkerId'] ?? 0) === (int) $worker->id)
                ->map(static fn (array $room): array => Arr::only($room, [
                    'id', 'roomKey', 'roomType', 'roomSize', 'displayLabel',
                    'assignedWorkerId', 'assignmentSource',
                ]))
                ->values()->all();
        }
        if ($this->shouldScopeV2WorkerRooms($booking)) {
            $safe = $this->scopeNestedWorkerRoomData($safe, $ownedRooms);
        }

        return $safe;
    }

    private function workerVisitProjection(CleaningBooking $booking, Worker $worker): array
    {
        return $booking->sessions()
            ->whereHas('workerAssignments', function ($assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->with(['workerAssignments' => function ($assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            }])
            ->orderBy('sequence')
            ->get()
            ->map(static function ($session): array {
                $assignment = $session->workerAssignments->first();

                return [
                    'id' => (int) $session->id,
                    'sequence' => (int) $session->sequence,
                    'status' => $session->status?->value ?? (string) $session->status,
                    'scheduledDate' => $session->scheduled_date?->toDateString(),
                    'scheduledTime' => mb_substr((string) $session->scheduled_time, 0, 5),
                    'requiredWorkers' => $session->requiredWorkerCount(),
                    'attendance' => [
                        'status' => $assignment?->status?->value ?? $assignment?->status,
                        'acceptedAt' => $assignment?->accepted_at?->toIso8601String(),
                        'startedTravelAt' => $assignment?->started_travel_at?->toIso8601String(),
                        'arrivedAt' => $assignment?->arrived_at?->toIso8601String(),
                        'workStartedAt' => $assignment?->work_started_at?->toIso8601String(),
                        'workFinishedAt' => $assignment?->work_finished_at?->toIso8601String(),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function workerOwnedRoomProjection(CleaningBooking $booking, Worker $worker): array
    {
        $sessionIds = $booking->sessions()
            ->whereHas('workerAssignments', function ($assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })
            ->pluck('id');
        if ($sessionIds->isEmpty()) {
            return [];
        }

        $scheduledRooms = $booking->roomSchedules()
            ->with('room')
            ->whereIn('cleaning_booking_session_id', $sessionIds)
            ->get()
            ->map(static fn ($schedule): array => [
                'id' => (int) $schedule->cleaning_booking_room_id,
                'roomKey' => $schedule->room?->room_key,
                'roomType' => $schedule->room?->room_type,
                'roomSize' => $schedule->room?->room_size,
                'displayLabel' => $schedule->room?->display_label,
                'assignedWorkerId' => $schedule->room?->assigned_worker_id,
            ]);
        $periodRooms = $booking->roomPeriods()
            ->with('room')
            ->whereIn('cleaning_booking_session_id', $sessionIds)
            ->get()
            ->map(static fn ($period): array => [
                'id' => (int) $period->cleaning_booking_room_id,
                'roomKey' => $period->room?->room_key,
                'roomType' => $period->room_type,
                'roomSize' => $period->room_size,
                'displayLabel' => $period->room?->display_label,
                'assignedWorkerId' => $period->room?->assigned_worker_id,
            ]);

        return $scheduledRooms->concat($periodRooms)
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id:int,roomKey:mixed,roomType:mixed,roomSize:mixed,displayLabel:mixed,assignedWorkerId:mixed}>  $ownedRooms
     */
    private function scopeNestedWorkerRoomData(array $safe, array $ownedRooms): array
    {
        $ownedIds = array_fill_keys(array_map(static fn (array $room): int => (int) $room['id'], $ownedRooms), true);
        $ownedKeys = array_fill_keys(array_values(array_filter(array_map(
            static fn (array $room): ?string => is_string($room['roomKey'] ?? null) ? mb_trim($room['roomKey']) : null,
            $ownedRooms,
        ))), true);
        $ownedLabels = array_fill_keys(array_values(array_filter(array_map(
            static fn (array $room): ?string => is_string($room['displayLabel'] ?? null) ? mb_trim($room['displayLabel']) : null,
            $ownedRooms,
        ))), true);

        foreach (['workerOffer', 'worker_offer', 'myAssignment', 'worker_assignment'] as $key) {
            if (! is_array($safe[$key] ?? null)) {
                continue;
            }

            $nested = $safe[$key];
            if (is_array($nested['roomIds'] ?? null)) {
                $nested['roomIds'] = array_values(array_filter(
                    $nested['roomIds'],
                    static fn (mixed $roomId): bool => isset($ownedIds[(int) $roomId]),
                ));
            }
            foreach (['workerFinishedPropertyRooms', 'worker_finished_property_rooms'] as $roomsKey) {
                if (! is_array($nested[$roomsKey] ?? null)) {
                    continue;
                }
                $nested[$roomsKey] = array_values(array_filter(
                    $nested[$roomsKey],
                    static function (mixed $snapshot) use ($ownedIds, $ownedKeys, $ownedLabels): bool {
                        if (! is_array($snapshot)) {
                            return false;
                        }
                        if (is_numeric($snapshot['id'] ?? null)) {
                            return isset($ownedIds[(int) $snapshot['id']]);
                        }
                        $roomKey = $snapshot['roomKey'] ?? $snapshot['room_key'] ?? null;
                        if (is_string($roomKey) && mb_trim($roomKey) !== '') {
                            return isset($ownedKeys[mb_trim($roomKey)]);
                        }
                        $label = $snapshot['displayLabel'] ?? $snapshot['display_label'] ?? $snapshot['name'] ?? $snapshot['label'] ?? null;

                        return is_string($label) && mb_trim($label) !== '' && isset($ownedLabels[mb_trim($label)]);
                    },
                ));
            }
            $safe[$key] = $nested;
        }

        return $safe;
    }

    private function shouldScopeV2WorkerRooms(CleaningBooking $booking): bool
    {
        if ((int) $booking->number_of_workers <= 1
            || ! in_array((string) $booking->booking_kind, ['standard', 'open_time'], true)) {
            return false;
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];

        return $booking->isV2OperationalGroup()
            || array_key_exists('_time_period_v2', $details)
            || array_key_exists('_room_period_v2_approved_total', $details);
    }
}
