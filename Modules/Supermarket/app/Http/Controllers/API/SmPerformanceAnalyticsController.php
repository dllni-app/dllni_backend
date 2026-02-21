<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Http\Requests\SmPerformanceAnalyticsRequest;
use Modules\Supermarket\Services\ReportService;

final class SmPerformanceAnalyticsController
{
    public function __construct(
        private ReportService $reportService,
    ) {}

    public function index(SmPerformanceAnalyticsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $analytics = $this->reportService->getPerformanceAnalytics(
            startDate: Carbon::parse($data['startDate']),
            endDate: Carbon::parse($data['endDate']),
            storeId: isset($data['storeId']) ? (int) $data['storeId'] : null,
        );

        return response()->json($analytics);
    }
}
