<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\WorkerZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;

final class GeographicCoverageController
{
    public function __invoke(Request $request): JsonResponse
    {
        $zonesWithWorkers = WorkerZone::query()
            ->where('is_active', true)
            ->select('name', DB::raw('COUNT(DISTINCT worker_id) as worker_count'))
            ->groupBy('name')
            ->get()
            ->map(fn ($row) => [
                'zoneName' => $row->name,
                'workerCount' => $row->worker_count,
            ]);

        $bookingsByPropertyType = CleaningBooking::query()
            ->whereNotNull('property_type')
            ->select('property_type', DB::raw('COUNT(*) as booking_count'))
            ->groupBy('property_type')
            ->get()
            ->map(fn ($row) => [
                'propertyType' => $row->property_type,
                'bookingCount' => $row->booking_count,
            ]);

        return response()->json([
            'zones' => $zonesWithWorkers,
            'demandByPropertyType' => $bookingsByPropertyType,
        ]);
    }
}
