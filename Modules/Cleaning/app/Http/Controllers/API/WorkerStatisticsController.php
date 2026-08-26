<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cleaning\Services\WorkerSessionMetricsService;

final class WorkerStatisticsController
{
    public function __construct(
        private readonly WorkerSessionMetricsService $sessionMetrics,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $worker = auth()->user()?->worker;

        if (! $worker) {
            return response()->json([
                'range' => 'this_week',
                'summary' => [
                    'totalBookings' => 0,
                    'totalEarnings' => 0.0,
                    'confirmedCount' => 0,
                    'cancelledCount' => 0,
                    'disputedCount' => 0,
                ],
                'chart' => [],
            ]);
        }

        $today = Carbon::today();

        return response()->json($this->sessionMetrics->weeklyStatistics(
            worker: $worker,
            start: $today->copy()->startOfWeek(),
            end: $today->copy()->endOfWeek(),
        ));
    }
}
