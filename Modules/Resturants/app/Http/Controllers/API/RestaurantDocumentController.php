<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\RestaurantDocumentData;
use Modules\Resturants\Http\Requests\RestaurantDocumentRequest;
use Modules\Resturants\Http\Requests\RestaurantDocumentRequests\RestaurantDocumentFilterRequest;
use Modules\Resturants\Http\Resources\RestaurantDocumentResource;
use Modules\Resturants\Models\RestaurantDocument;
use Modules\Resturants\Services\RestaurantDocumentService;
use Throwable;

final class RestaurantDocumentController
{
    public function __construct(
        private RestaurantDocumentService $documentService
    ) {}

    public function index(RestaurantDocumentFilterRequest $request): AnonymousResourceCollection
    {
        $documents = RestaurantDocument::getQuery()
            ->with(['restaurant'])
            ->paginate($request->get('perPage', 20));

        return RestaurantDocumentResource::collection($documents);
    }

    /** @throws Throwable */
    public function store(RestaurantDocumentRequest $request): RestaurantDocumentResource
    {
        $document = $this->documentService->store(
            RestaurantDocumentData::from($request->validated())
        );

        return RestaurantDocumentResource::make($document->load(['restaurant']));
    }

    public function show(RestaurantDocument $restaurant_document): RestaurantDocumentResource
    {
        $restaurant_document->load(['restaurant']);

        return RestaurantDocumentResource::make($restaurant_document);
    }

    /** @throws Throwable */
    public function update(RestaurantDocumentRequest $request, RestaurantDocument $restaurant_document): RestaurantDocumentResource
    {
        $updated = $this->documentService->update(
            RestaurantDocumentData::from($request->validated()),
            $restaurant_document
        );

        return RestaurantDocumentResource::make($updated->load(['restaurant']));
    }

    public function destroy(RestaurantDocument $restaurant_document): Response
    {
        $restaurant_document->delete();

        return response()->noContent();
    }
}
