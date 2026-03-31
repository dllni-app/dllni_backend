<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Services\SmLuckBoxService;

final class SmLuckBoxOptionsController
{
    public function __construct(
        private SmLuckBoxService $service,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->service->options());
    }
}
