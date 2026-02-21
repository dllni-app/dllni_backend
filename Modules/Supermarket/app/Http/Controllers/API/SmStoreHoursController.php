<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmStoreHoursData;
use Modules\Supermarket\Http\Requests\SmStoreHoursRequest;
use Modules\Supermarket\Http\Requests\SmStoreHoursRequests\SmStoreHoursFilterRequest;
use Modules\Supermarket\Http\Resources\SmStoreHoursResource;
use Modules\Supermarket\Models\SmStoreHours;
use Modules\Supermarket\Services\SmStoreHoursService;

final class SmStoreHoursController
{
    public function __construct(
        private SmStoreHoursService $service
    ) {}

    public function index(SmStoreHoursFilterRequest $request): AnonymousResourceCollection
    {
        $hours = SmStoreHours::getQuery()->paginate($request->get('perPage', 20));

        return SmStoreHoursResource::collection($hours);
    }

    public function store(SmStoreHoursRequest $request): SmStoreHoursResource
    {
        $hours = $this->service->store(SmStoreHoursData::from($request->validated()));

        return SmStoreHoursResource::make($hours->load('store'));
    }

    public function show(SmStoreHours $smStoreHour): SmStoreHoursResource
    {
        return SmStoreHoursResource::make($smStoreHour->load('store'));
    }

    public function update(SmStoreHoursRequest $request, SmStoreHours $smStoreHour): SmStoreHoursResource
    {
        $hours = $this->service->update(SmStoreHoursData::from($request->validated()), $smStoreHour);

        return SmStoreHoursResource::make($hours->load('store'));
    }

    public function destroy(SmStoreHours $smStoreHour): Response
    {
        $smStoreHour->delete();

        return response()->noContent();
    }
}
