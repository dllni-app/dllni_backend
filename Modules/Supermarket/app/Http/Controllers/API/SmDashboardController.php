<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Services\ReportService;

final class SmDashboardController
{
    public function __construct(
        private ReportService $reportService,
    ) {}

    public function index(): JsonResponse
    {
        $dashboard = $this->reportService->getDashboardData();

        return response()->json($dashboard);
    }
}
