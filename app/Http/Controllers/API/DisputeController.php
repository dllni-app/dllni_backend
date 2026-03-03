<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Data\DisputeData;
use App\Http\Requests\DisputeRequest;
use App\Http\Requests\DisputeRequests\DisputeFilterRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
}
