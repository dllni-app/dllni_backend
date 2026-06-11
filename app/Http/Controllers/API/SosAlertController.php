<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\SosAlertRequests\SosAlertFilterRequest;
use App\Http\Resources\SosAlertResource;
use App\Models\SosAlert;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SosAlertController
{
    public function index(SosAlertFilterRequest $request): AnonymousResourceCollection
    {
        $alerts = SosAlert::getQuery()
            ->with(['booking', 'user', 'order'])
            ->paginate($request->get('perPage', 10));

        return SosAlertResource::collection($alerts);
    }

    public function show(SosAlert $sos_alert): SosAlertResource
    {
        $sos_alert->load(['booking', 'user', 'order']);

        return SosAlertResource::make($sos_alert);
    }
}
