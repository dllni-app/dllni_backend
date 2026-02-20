<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Cleaning\Data\CleaningServiceData;
use Modules\Cleaning\Http\Requests\CleaningServiceRequest;
use Modules\Cleaning\Http\Requests\CleaningServiceRequests\CleaningServiceFilterRequest;
use Modules\Cleaning\Http\Resources\CleaningServiceResource;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Services\CleaningServiceService;
use Throwable;

final class CleaningServiceController
{
    public function __construct(
        private readonly CleaningServiceService $cleaningServiceService
    ) {}

    public function index(CleaningServiceFilterRequest $request): AnonymousResourceCollection
    {
        $services = CleaningService::getQuery()
            ->with(['pricing'])
            ->paginate($request->get('perPage', 20));

        return CleaningServiceResource::collection($services);
    }

    /** @throws Throwable */
    public function store(CleaningServiceRequest $request): CleaningServiceResource
    {
        $service = $this->cleaningServiceService->store(
            CleaningServiceData::from($request->validated())
        );

        return CleaningServiceResource::make(
            $service->load(['pricing'])
        );
    }

    public function show(CleaningService $cleaning_service): CleaningServiceResource
    {
        $cleaning_service->load(['pricing']);

        return CleaningServiceResource::make($cleaning_service);
    }

    /** @throws Throwable */
    public function update(CleaningServiceRequest $request, CleaningService $cleaning_service): CleaningServiceResource
    {
        $updated = $this->cleaningServiceService->update(
            CleaningServiceData::from($request->validated()),
            $cleaning_service
        );

        return CleaningServiceResource::make(
            $updated->load(['pricing'])
        );
    }

    public function destroy(CleaningService $cleaning_service): Response
    {
        $cleaning_service->delete();

        return response()->noContent();
    }
}
