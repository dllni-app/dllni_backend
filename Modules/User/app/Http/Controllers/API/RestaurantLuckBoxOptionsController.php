<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Services\RestaurantLuckBoxService;

final class RestaurantLuckBoxOptionsController
{
    public function __construct(
        private RestaurantLuckBoxService $service,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->service->options());
    }
}
