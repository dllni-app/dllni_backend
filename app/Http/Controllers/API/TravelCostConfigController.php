<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Data\TravelCostConfigData;
use App\Http\Requests\TravelCostConfigRequest;
use App\Http\Requests\TravelCostConfigRequests\TravelCostConfigFilterRequest;
use App\Http\Resources\TravelCostConfigResource;
use App\Models\TravelCostConfig;
use App\Services\TravelCostConfigService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Throwable;

final class TravelCostConfigController
{
    public function __construct(
        private readonly TravelCostConfigService $travelCostConfigService
    ) {}

    public function index(TravelCostConfigFilterRequest $request): AnonymousResourceCollection
    {
        $configs = TravelCostConfig::getQuery()
            ->paginate($request->get('perPage', 20));

        return TravelCostConfigResource::collection($configs);
    }

    /** @throws Throwable */
    public function store(TravelCostConfigRequest $request): TravelCostConfigResource
    {
        $config = $this->travelCostConfigService->store(
            TravelCostConfigData::from($request->validated())
        );

        return TravelCostConfigResource::make($config);
    }

    public function show(TravelCostConfig $travel_cost_config): TravelCostConfigResource
    {
        return TravelCostConfigResource::make($travel_cost_config);
    }

    /** @throws Throwable */
    public function update(TravelCostConfigRequest $request, TravelCostConfig $travel_cost_config): TravelCostConfigResource
    {
        $updated = $this->travelCostConfigService->update(
            TravelCostConfigData::from($request->validated()),
            $travel_cost_config
        );

        return TravelCostConfigResource::make($updated);
    }

    public function destroy(TravelCostConfig $travel_cost_config): Response
    {
        $travel_cost_config->delete();

        return response()->noContent();
    }
}
