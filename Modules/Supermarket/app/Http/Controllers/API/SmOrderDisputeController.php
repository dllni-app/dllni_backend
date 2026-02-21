<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmOrderDisputeData;
use Modules\Supermarket\Http\Requests\SmOrderDisputeRequest;
use Modules\Supermarket\Http\Requests\SmOrderDisputeRequests\SmOrderDisputeFilterRequest;
use Modules\Supermarket\Http\Resources\SmOrderDisputeResource;
use Modules\Supermarket\Models\SmOrderDispute;
use Modules\Supermarket\Services\SmOrderDisputeService;

final class SmOrderDisputeController
{
    public function __construct(
        private SmOrderDisputeService $service
    ) {}

    public function index(SmOrderDisputeFilterRequest $request): AnonymousResourceCollection
    {
        $disputes = SmOrderDispute::getQuery()->paginate($request->get('perPage', 20));

        return SmOrderDisputeResource::collection($disputes);
    }

    public function store(SmOrderDisputeRequest $request): SmOrderDisputeResource
    {
        $dispute = $this->service->store(SmOrderDisputeData::from($request->validated()));

        return SmOrderDisputeResource::make($dispute->load(['order', 'openedByUser', 'resolvedByUser', 'messages']));
    }

    public function show(SmOrderDispute $smOrderDispute): SmOrderDisputeResource
    {
        return SmOrderDisputeResource::make($smOrderDispute->load(['order', 'openedByUser', 'resolvedByUser', 'messages']));
    }

    public function update(SmOrderDisputeRequest $request, SmOrderDispute $smOrderDispute): SmOrderDisputeResource
    {
        $dispute = $this->service->update(SmOrderDisputeData::from($request->validated()), $smOrderDispute);

        return SmOrderDisputeResource::make($dispute->load(['order', 'openedByUser', 'resolvedByUser', 'messages']));
    }

    public function destroy(SmOrderDispute $smOrderDispute): Response
    {
        $smOrderDispute->delete();

        return response()->noContent();
    }
}
