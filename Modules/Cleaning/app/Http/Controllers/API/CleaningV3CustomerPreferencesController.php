<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningV3CustomerPreferences;

final class CleaningV3CustomerPreferencesController
{
    public function __invoke(Request $request, CleaningV3CustomerPreferences $preferences): JsonResponse
    {
        $id = (int) $request->user()->id;
        return response()->json(['data' => [
            'addresses' => $preferences->addresses($id),
            'previousWorkers' => $preferences->history($id)->limit(100)->get(['id', 'first_name'])
                ->map(fn ($w) => ['workerId' => (int) $w->id, 'name' => (string) $w->first_name])->all(),
        ]], 200, ['Cache-Control' => 'private, no-store']);
    }
}
