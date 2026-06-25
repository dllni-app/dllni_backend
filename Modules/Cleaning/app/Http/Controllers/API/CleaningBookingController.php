<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SOSStatus;
use App\Enums\SystemAlertStatus;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function index(CleaningBookingFilterRequest $request): AnonymousResourceCollection
    {
        $bookings = CleaningBooking::getQuery()
            ->with([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'addons',
                'billingPolicy',
                'timeWarnings',
                'disputes',
            ])
            ->paginate($request->get('perPage', 20));

        $worker = $request->user()?->worker;

        if ($worker instanceof Worker) {
            $worker->loadMissing('deposit');
            $this->filterFinanciallyBlockedPendingBookings($bookings, $worker);
        }

        $collection = CleaningBookingResource::collection($bookings);

        if ($worker instanceof Worker) {
            $collection->additional([
                'dispatchEligibility' => $this->newRequestEligibility(
                    $worker,
                    $this->depositService->depositStatusPayload($worker),
                ),
            ]);
        }

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
            CleaningBookingData::from($request->validated()),
            $cleaning_booking
        );

        return CleaningBookingResource::make(
            $this->loadBookingDetails($updated)
        );
    }

    public function destroy(CleaningBooking $cleaning_booking): Response
    {
        $cleaning_booking->delete();

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
            ? 'Worker balance and allowed negative limit do not cover this booking platform commission.'
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

        if (($depositSummary['exceedanceAmount'] ?? null) !== null) {
            return 'deposit_below_allowed_balance';
        }

        return 'trust_score_too_low';
    }

    private function eligibilityMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'eligible' => 'Your account can receive and accept new requests.',
            'worker_inactive' => 'Your account is inactive. Reactivate your account to receive new requests.',
            'worker_suspended' => 'Your account is suspended. Please contact support for more details.',
            'deposit_below_allowed_balance' => 'Your deposit balance is below the allowed limit. Please recharge your deposit account to receive new requests.',
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
}
