<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Modules\Supermarket\Http\Requests\SmFinancialReportRequest;
use Modules\Supermarket\Services\ReportService;

final class SmFinancialReportController
{
    public function __construct(
        private ReportService $reportService,
    ) {}

    public function index(SmFinancialReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        $report = $this->reportService->getFinancialReport(
            startDate: Carbon::parse($data['startDate']),
            endDate: Carbon::parse($data['endDate']),
            storeId: isset($data['storeId']) ? (int) $data['storeId'] : null,
            status: $data['status'] ?? null,
        );

        return response()->json($report);
    }
}
