<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Http\Resources\WorkerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class WorkerProfileController
{
    public function __invoke(): WorkerResource|JsonResponse
    {
        $worker = auth()->user()?->worker;

        if (! $worker) {
            abort(Response::HTTP_FORBIDDEN, 'User must have an associated worker.');
        }

        $worker->load(['user', 'zones', 'availability' , 'media']);

        return WorkerResource::make($worker);
    }
}
