<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningWorkerApkUpdateManifestService;

final class CleaningWorkerApkUpdateManifestController
{
    public function __invoke(
        Request $request,
        CleaningWorkerApkUpdateManifestService $service,
    ): JsonResponse {
        abort_unless($request->user()?->is_active
            && $request->user()?->worker !== null, 404);

        try {
            $manifest = $service->manifest();
        } catch (DomainException) {
            return response()->json([
                'success' => false,
                'code' => 'WORKER_APK_UPDATE_NOT_AVAILABLE',
                'message' => 'No verified signed worker APK update is currently published.',
                'retryable' => false,
            ], 503, ['Cache-Control' => 'private, no-store']);
        }

        return response()->json([
            'success' => true,
            'data' => $manifest,
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
