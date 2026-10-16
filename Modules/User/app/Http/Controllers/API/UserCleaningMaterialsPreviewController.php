<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserCleaningMaterialsPreviewRequest;
use Modules\User\Services\CleaningTimePeriodMaterialsPreviewService;

final class UserCleaningMaterialsPreviewController
{
    public function __invoke(
        UserCleaningMaterialsPreviewRequest $request,
        CleaningTimePeriodMaterialsPreviewService $preview,
    ): JsonResponse {
        return response()->json([
            'contractVersion' => 2,
            'materialsPreview' => $preview->preview($request->validated('rooms')),
        ]);
    }
}
