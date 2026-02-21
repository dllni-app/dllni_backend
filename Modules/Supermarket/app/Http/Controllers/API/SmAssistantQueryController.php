<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmAssistantQueryRequests\SmAssistantQueryFilterRequest;
use Modules\Supermarket\Http\Resources\SmAssistantQueryResource;
use Modules\Supermarket\Models\SmAssistantQuery;

final class SmAssistantQueryController
{
    public function index(SmAssistantQueryFilterRequest $request): AnonymousResourceCollection
    {
        $queries = SmAssistantQuery::getQuery()->paginate($request->get('perPage', 20));

        return SmAssistantQueryResource::collection($queries);
    }

    public function show(SmAssistantQuery $smAssistantQuery): SmAssistantQueryResource
    {
        return SmAssistantQueryResource::make($smAssistantQuery->load(['user', 'store', 'matchedRecipe']));
    }
}
