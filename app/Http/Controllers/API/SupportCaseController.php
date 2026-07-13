<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Enums\SupportCaseKind;
use App\Enums\SupportCaseStatus;
use App\Http\Requests\SupportCaseMessageStoreRequest;
use App\Http\Requests\SupportCaseStoreRequest;
use App\Http\Resources\SupportCaseResource;
use App\Models\SupportCase;
use App\Models\User;
use App\Services\SupportCaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Cleaning\Models\CleaningBooking;

final class SupportCaseController
{
    public function __construct(private readonly SupportCaseService $supportCaseService) {}

    public function index(): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = request()->user();

        $query = $this->authorizedQuery($user)
            ->with(['reporter'])
            ->withCount('messages')
            ->latest('id');

        if (filled(request('kind'))) {
            $query->where('kind', request('kind'));
        }

        if (filled(request('status'))) {
            $query->where('status', request('status'));
        }

        return SupportCaseResource::collection(
            $query->paginate((int) request('perPage', 15))
        );
    }

    public function store(SupportCaseStoreRequest $request): SupportCaseResource
    {
        /** @var User $user */
        $user = $request->user();

        $supportCase = $this->supportCaseService->create(
            reporter: $user,
            data: $request->validated(),
            attachments: $request->file('attachments', []),
        );

        return SupportCaseResource::make($supportCase)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED)
            ->getOriginalContent();
    }

    public function show(SupportCase $supportCase): SupportCaseResource
    {
        /** @var User $user */
        $user = request()->user();
        $this->ensureCanAccess($user, $supportCase);

        return SupportCaseResource::make($this->loadDetails($supportCase));
    }

    public function storeMessage(
        SupportCaseMessageStoreRequest $request,
        SupportCase $supportCase,
    ): SupportCaseResource {
        /** @var User $user */
        $user = $request->user();
        $this->ensureCanAccess($user, $supportCase);

        $role = $this->supportCaseService->reporterRoleFor($user, $supportCase->loadMissing('booking'));

        $this->supportCaseService->addMessage(
            supportCase: $supportCase,
            sender: $user,
            senderRole: $role,
            body: (string) $request->validated('message'),
            attachments: $request->file('attachments', []),
        );

        return SupportCaseResource::make($this->loadDetails($supportCase->fresh()));
    }

    private function authorizedQuery(User $user): Builder
    {
        $query = SupportCase::query();

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return $query;
        }

        $workerId = $user->worker?->id;

        return $query->where(function (Builder $scope) use ($user, $workerId): void {
            $scope->where('reporter_id', $user->id)
                ->orWhereHasMorph(
                    'booking',
                    [CleaningBooking::class],
                    function (Builder $bookingQuery) use ($user, $workerId): void {
                        $bookingQuery->where('customer_id', $user->id);

                        if ($workerId !== null) {
                            $bookingQuery->orWhere('worker_id', $workerId)
                                ->orWhereHas('workerAssignments', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('worker_id', $workerId));
                        }
                    },
                );
        });
    }

    private function ensureCanAccess(User $user, SupportCase $supportCase): void
    {
        abort_unless(
            $this->authorizedQuery($user)->whereKey($supportCase->getKey())->exists(),
            Response::HTTP_FORBIDDEN,
            'You cannot access this support case.',
        );
    }

    private function loadDetails(SupportCase $supportCase): SupportCase
    {
        return $supportCase->load([
            'reporter',
            'messages.sender',
            'messages.media',
            'events.actor',
            'media',
            'booking' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    CleaningBooking::class => ['customer', 'worker.user'],
                ]);
            },
        ]);
    }
}
