<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Cleaning\Http\Requests\CleaningTimeWarningRequests\CleaningTimeWarningFilterRequest;
use Modules\Cleaning\Http\Resources\CleaningTimeWarningResource;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningTimeWarningController
{
    public function index(CleaningTimeWarningFilterRequest $request): AnonymousResourceCollection
    {
        $warnings = CleaningTimeWarning::getQuery()
            ->with(['booking'])
            ->paginate($request->get('perPage', 20));

        return CleaningTimeWarningResource::collection($warnings);
    }

    public function show(CleaningTimeWarning $cleaning_time_warning): CleaningTimeWarningResource
    {
        $cleaning_time_warning->load(['booking']);

        return CleaningTimeWarningResource::make($cleaning_time_warning);
    }
}
