<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmStoreDocumentData;
use Modules\Supermarket\Http\Requests\SmStoreDocumentRequest;
use Modules\Supermarket\Http\Requests\SmStoreDocumentRequests\SmStoreDocumentFilterRequest;
use Modules\Supermarket\Http\Resources\SmStoreDocumentResource;
use Modules\Supermarket\Models\SmStoreDocument;
use Modules\Supermarket\Services\SmStoreDocumentService;

final class SmStoreDocumentController
{
    public function __construct(
        private SmStoreDocumentService $service
    ) {}

    public function index(SmStoreDocumentFilterRequest $request): AnonymousResourceCollection
    {
        $documents = SmStoreDocument::getQuery()->paginate($request->get('perPage', 20));

        return SmStoreDocumentResource::collection($documents);
    }

    public function store(SmStoreDocumentRequest $request): SmStoreDocumentResource
    {
        $document = $this->service->store(SmStoreDocumentData::from($request->validated()));

        return SmStoreDocumentResource::make($document->load(['store', 'verifiedByUser']));
    }

    public function show(SmStoreDocument $smStoreDocument): SmStoreDocumentResource
    {
        return SmStoreDocumentResource::make($smStoreDocument->load(['store', 'verifiedByUser']));
    }

    public function update(SmStoreDocumentRequest $request, SmStoreDocument $smStoreDocument): SmStoreDocumentResource
    {
        $document = $this->service->update(SmStoreDocumentData::from($request->validated()), $smStoreDocument);

        return SmStoreDocumentResource::make($document->load(['store', 'verifiedByUser']));
    }

    public function destroy(SmStoreDocument $smStoreDocument): Response
    {
        $smStoreDocument->delete();

        return response()->noContent();
    }
}
