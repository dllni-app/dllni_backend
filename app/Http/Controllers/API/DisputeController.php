<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Data\DisputeData;
use App\Http\Requests\DisputeMessageStoreRequest;
use App\Http\Requests\DisputeRequest;
use App\Http\Requests\DisputeRequests\DisputeFilterRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Models\CleaningBooking;
use Throwable;

final class DisputeController
{
    public function __construct(
        private readonly DisputeService $disputeService
    ) {}

    public function index(DisputeFilterRequest $request): AnonymousResourceCollection
    {
        $disputes = Dispute::getQuery()
            ->with(['booking'])
            ->paginate($request->get('perPage', 10));

        return DisputeResource::collection($disputes);
    }

    /** @throws Throwable */
    public function store(DisputeRequest $request): DisputeResource
    {
        $dispute = $this->disputeService->store(DisputeData::from($request->validated()));

        return DisputeResource::make($dispute->load(['booking', 'messages']));
    }

    public function show(Dispute $dispute): DisputeResource
    {
        $dispute->load(['booking', 'messages']);

        return DisputeResource::make($dispute);
    }

    public function storeMessage(DisputeMessageStoreRequest $request, Dispute $dispute): JsonResponse
    {
        $this->ensureWorkerCanReply($dispute);

        DisputeMessage::create([
            'dispute_id' => $dispute->id,
            'sender_id' => $request->user()->id,
            'sender_type' => 'worker',
            'body' => $request->validated('message'),
        ]);

        return DisputeResource::make($dispute->fresh()->load(['booking', 'messages.sender']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** @throws Throwable */
    public function update(DisputeRequest $request, Dispute $dispute): DisputeResource
    {
        $updated = $this->disputeService->update(DisputeData::from($request->validated()), $dispute);

        return DisputeResource::make($updated->load(['booking', 'messages']));
    }

    public function destroy(Dispute $dispute): Response
    {
        $dispute->delete();

        return response()->noContent();
    }

    private function ensureWorkerCanReply(Dispute $dispute): void
    {
        $workerId = Auth::user()?->worker?->id;

        if (! $workerId) {
            abort(Response::HTTP_FORBIDDEN, 'User must have an associated worker.');
        }

        if ($dispute->booking_type !== 'cleaning_booking') {
            abort(Response::HTTP_FORBIDDEN, 'Worker can only reply to cleaning booking disputes.');
        }

        $booking = $dispute->booking;

        if (! $booking instanceof CleaningBooking || $booking->worker_id !== $workerId) {
            abort(Response::HTTP_FORBIDDEN, 'Dispute is not assigned to the authenticated worker.');
        }
    }
}
