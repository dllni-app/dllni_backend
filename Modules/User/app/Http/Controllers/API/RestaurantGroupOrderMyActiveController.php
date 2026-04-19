<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\User\Services\RestaurantGroupOrderService;

final class RestaurantGroupOrderMyActiveController
{
    public function __construct(
        private readonly RestaurantGroupOrderService $service,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->activeOrdersForUser((int) Auth::id()),
        ]);
    }
}
