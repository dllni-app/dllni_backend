<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Data\DisputeData;
use App\Enums\DisputeStatus;
use App\Http\Requests\DisputeMessageStoreRequest;
use App\Http\Requests\DisputeRequest;
use App\Http\Requests\DisputeRequests\DisputeFilterRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\User;
use App\Services\DisputeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Cleaning\Models\CleaningBooking;
use Throwable;

final class DisputeController
{
    public function __construct(
        private readonly DisputeService $disputeService
    ) {}

    public function index(DisputeFilterRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $disputes = $this->authorizedQuery($user)
            ->with(['booking', 'messages.sender'])
            ->paginate($request->get('perPage', 10));

        return DisputeResource::collection($disputes);
    }

    /** @throws Throwable */
    public function store(DisputeRequest $request): DisputeResource
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        if (! $this->isAdmin($user)) {
            abort_unless($data['bookingType'] === 'cleaning_booking', Response::HTTP_FORBIDDEN);

            $booking = CleaningBooking::query()->findOrFail((int) $data['bookingId']);
            abort_unless((int) $booking->customer_id === (int) $user->id, Response::HTTP_FORBIDDEN);
        }

        $data['ticketNumber'] ??= 'DSP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        $data['status'] ??= DisputeStatus::Open->value;

        $dispute = $this->disputeService->store(DisputeData::from($data));

        return DisputeResource::make($dispute->load(['booking', 'messages.sender']));
    }

    public function show(Dispute $dispute): DisputeResource
    {
        /** @var User $user */
        $user = request()->user();
        $this->ensureCanAccess($user, $dispute);

        return DisputeResource::make($dispute->load(['booking', 'messages.sender']));
    }

    public function storeMessage(DisputeMessageStoreRequest $request, Dispute $dispute): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $senderType = $this->participantRole($user, $dispute);

        DisputeMessage::query()->create([
            'dispute_id' => $dispute->id,
            'sender_id' => $user->id,
            'sender_type' => $senderType,
            'body' => $request->validated('message'),
        ]);

        return DisputeResource::make($dispute->fresh()->load(['booking', 'messages.sender']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** @throws Throwable */
    public function update(DisputeRequest $request, Dispute $dispute): DisputeResource
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->isAdmin($user), Response::HTTP_FORBIDDEN);

        $updated = $this->disputeService->update(DisputeData::from($request->validated()), $dispute);

        return DisputeResource::make($updated->load(['booking', 'messages.sender']));
    }

    public function destroy(Dispute $dispute): Response
    {
        /** @var User $user */
        $user = request()->user();
        abort_unless($this->isAdmin($user), Response::HTTP_FORBIDDEN);

        $dispute->delete();

        return response()->noContent();
    }

    private function authorizedQuery(User $user): Builder
    {
        $query = Dispute::getQuery();

        if ($this->isAdmin($user)) {
            return $query;
        }

        $workerId = $user->worker?->id;

        return $query->whereHasMorph(
            'booking',
            [CleaningBooking::class],
            function (Builder $bookingQuery) use ($user, $workerId): void {
                $bookingQuery->where('customer_id', $user->id);

                if ($workerId !== null) {
                    $bookingQuery->orWhere('worker_id', $workerId)
                        ->orWhereHas(
                            'workerAssignments',
                            fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('worker_id', $workerId),
                        );
                }
            },
        );
    }

    private function ensureCanAccess(User $user, Dispute $dispute): void
    {
        abort_unless(
            $this->authorizedQuery($user)->whereKey($dispute->getKey())->exists(),
            Response::HTTP_FORBIDDEN,
            'You cannot access this dispute.',
        );
    }

    private function participantRole(User $user, Dispute $dispute): string
    {
        if ($this->isAdmin($user)) {
            return 'admin';
        }

        $booking = $dispute->booking;
        if (! $booking instanceof CleaningBooking) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot reply to this dispute.');
        }

        if ((int) $booking->customer_id === (int) $user->id) {
            return 'customer';
        }

        $workerId = $user->worker?->id;
        $isWorker = $workerId !== null && (
            (int) $booking->worker_id === (int) $workerId
            || $booking->workerAssignments()->where('worker_id', $workerId)->exists()
        );

        abort_unless($isWorker, Response::HTTP_FORBIDDEN, 'You cannot reply to this dispute.');

        return 'worker';
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'Super Admin']);
    }
}
