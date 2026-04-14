<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmSmartListData;
use Modules\Supermarket\Http\Requests\SmSmartListRequest;
use Modules\Supermarket\Http\Requests\SmSmartListRequests\SmSmartListFilterRequest;
use Modules\Supermarket\Http\Resources\SmSmartListResource;
use Modules\Supermarket\Models\SmSmartList;
use Modules\Supermarket\Services\SmSmartListService;

final class SmSmartListController
{
    public function __construct(
        private SmSmartListService $service
    ) {}

    public function index(SmSmartListFilterRequest $request): AnonymousResourceCollection
    {
        $lists = SmSmartList::getQuery()->paginate($request->get('perPage', 20));

        return SmSmartListResource::collection($lists);
    }

    public function store(SmSmartListRequest $request): SmSmartListResource
    {
        $list = $this->service->store(SmSmartListData::from($request->validated()));

        return SmSmartListResource::make($list->load(['user', 'store', 'items', 'schedule']));
    }

    public function show(SmSmartList $smSmartList): SmSmartListResource
    {
        return SmSmartListResource::make($smSmartList->load(['user', 'store', 'items', 'schedule']));
    }

    public function update(SmSmartListRequest $request, SmSmartList $smSmartList): SmSmartListResource
    {
        $list = $this->service->update(SmSmartListData::from($request->validated()), $smSmartList);

        return SmSmartListResource::make($list->load(['user', 'store', 'items', 'schedule']));
    }

    public function destroy(SmSmartList $smSmartList): Response
    {
        $smSmartList->delete();

        return response()->noContent();
    }
}
