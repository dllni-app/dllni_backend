<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkerCurrentLocationController
{
    public function __invoke(Request $request): JsonResponse
    {
        $worker = $request->user()?->worker;
        if ($worker === null) {
            return response()->json(['message' => 'حساب العامل غير متاح.'], 403);
        }

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        DB::table('worker_latest_locations')->updateOrInsert(
            ['worker_id' => (int) $worker->id],
            [
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'recorded_at' => now(),
                'updated_at' => now(),
            ],
        );

        return response()->json(['message' => 'تم تحديث موقع العامل.']);
    }
}
