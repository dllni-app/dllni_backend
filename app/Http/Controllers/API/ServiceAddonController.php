<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Data\ServiceAddonData;
use App\Http\Requests\ServiceAddonRequest;
use App\Http\Requests\ServiceAddonRequests\ServiceAddonFilterRequest;
use App\Http\Resources\ServiceAddonResource;
use App\Models\ServiceAddon;
use App\Services\ServiceAddonService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Throwable;

final class ServiceAddonController
{
    public function __construct(
        private readonly ServiceAddonService $serviceAddonService
    ) {}

    public function index(ServiceAddonFilterRequest $request): AnonymousResourceCollection
    {
        $addons = ServiceAddon::getQuery()
            ->paginate($request->get('perPage', 10));

        return ServiceAddonResource::collection($addons);
    }

    /** @throws Throwable */
    public function store(ServiceAddonRequest $request): ServiceAddonResource
    {
        $addon = $this->serviceAddonService->store(
            ServiceAddonData::from($request->validated())
        );

        return ServiceAddonResource::make($addon);
    }

    public function show(ServiceAddon $service_addon): ServiceAddonResource
    {
        return ServiceAddonResource::make($service_addon);
    }

    /** @throws Throwable */
    public function update(ServiceAddonRequest $request, ServiceAddon $service_addon): ServiceAddonResource
    {
        $updated = $this->serviceAddonService->update(
            ServiceAddonData::from($request->validated()),
            $service_addon
        );

        return ServiceAddonResource::make($updated);
    }

    public function destroy(ServiceAddon $service_addon): Response
    {
        $service_addon->delete();

        return response()->noContent();
    }
}
