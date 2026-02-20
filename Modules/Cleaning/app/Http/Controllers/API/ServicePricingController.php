<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Cleaning\Data\ServicePricingData;
use Modules\Cleaning\Http\Requests\ServicePricingRequest;
use Modules\Cleaning\Http\Requests\ServicePricingRequests\ServicePricingFilterRequest;
use Modules\Cleaning\Http\Resources\ServicePricingResource;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\ServicePricing;
use Modules\Cleaning\Services\ServicePricingService;
use Throwable;

final class ServicePricingController
{
    public function __construct(
        private readonly ServicePricingService $servicePricingService
    ) {}

    public function index(ServicePricingFilterRequest $request, CleaningService $cleaning_service): AnonymousResourceCollection
    {
        $pricing = ServicePricing::getQuery()
            ->where('cleaning_service_id', $cleaning_service->id)
            ->with(['cleaningService'])
            ->paginate($request->get('perPage', 20));

        return ServicePricingResource::collection($pricing);
    }

    /** @throws Throwable */
    public function store(ServicePricingRequest $request, CleaningService $cleaning_service): ServicePricingResource
    {
        $validated = array_merge($request->validated(), [
            'cleaningServiceId' => $cleaning_service->id,
        ]);

        $pricing = $this->servicePricingService->store(
            ServicePricingData::from($validated)
        );

        return ServicePricingResource::make(
            $pricing->load(['cleaningService'])
        );
    }

    public function show(CleaningService $cleaning_service, ServicePricing $pricing): ServicePricingResource
    {
        $pricing->load(['cleaningService']);

        return ServicePricingResource::make($pricing);
    }

    /** @throws Throwable */
    public function update(ServicePricingRequest $request, CleaningService $cleaning_service, ServicePricing $pricing): ServicePricingResource
    {
        $validated = array_merge($request->validated(), [
            'cleaningServiceId' => $cleaning_service->id,
        ]);

        $updated = $this->servicePricingService->update(
            ServicePricingData::from($validated),
            $pricing
        );

        return ServicePricingResource::make(
            $updated->load(['cleaningService'])
        );
    }

    public function destroy(CleaningService $cleaning_service, ServicePricing $pricing): Response
    {
        $pricing->delete();

        return response()->noContent();
    }
}
