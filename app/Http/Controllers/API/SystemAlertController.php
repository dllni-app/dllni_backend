<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Data\SystemAlertData;
use App\Http\Requests\SystemAlertRequest;
use App\Http\Requests\SystemAlertRequests\SystemAlertFilterRequest;
use App\Http\Resources\SystemAlertResource;
use App\Models\SystemAlert;
use App\Services\SystemAlertService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

final class SystemAlertController
{
    public function __construct(
        private readonly SystemAlertService $systemAlertService
    ) {}

    public function index(SystemAlertFilterRequest $request): AnonymousResourceCollection
    {
        $alerts = SystemAlert::getQuery()
            ->with(['booking'])
            ->paginate($request->get('perPage', 20));

        return SystemAlertResource::collection($alerts);
    }

    public function show(SystemAlert $system_alert): SystemAlertResource
    {
        $system_alert->load(['booking']);

        return SystemAlertResource::make($system_alert);
    }

    /** @throws Throwable */
    public function update(SystemAlertRequest $request, SystemAlert $system_alert): SystemAlertResource
    {
        $updated = $this->systemAlertService->update(
            SystemAlertData::from($request->validated()),
            $system_alert
        );

        return SystemAlertResource::make($updated->load(['booking']));
    }
}
