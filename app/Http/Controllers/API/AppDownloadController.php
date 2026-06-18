<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Enums\AppDownloadType;
use App\Http\Requests\AppDownloadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

final class AppDownloadController
{
    public function __invoke(AppDownloadRequest $request)
    {
        $appType = AppDownloadType::from((string) $request->validated('appType'));
        $relativeFilePath = config("app_downloads.files.{$appType->value}");

        if (! is_string($relativeFilePath) || trim($relativeFilePath) === '') {
            return response()->json([
                'message' => 'The requested app package is not configured yet.',
                'appType' => $appType->value,
            ], 404);
        }

        $absoluteFilePath = storage_path('app/'.$relativeFilePath);
        if (! File::exists($absoluteFilePath)) {
            return response()->json([
                'message' => 'The requested app package is not available yet.',
                'appType' => $appType->value,
            ], 404);
        }

        return response()->download($absoluteFilePath);
    }
}

