<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Services\GeminiProductService;
use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserNormalizeProductTextRequest;

final class UserNormalizeProductTextController
{
    public function __construct(
        private readonly GeminiProductService $gemini,
    ) {}

    public function __invoke(UserNormalizeProductTextRequest $request): JsonResponse
    {
        $normalized = $this->gemini->normalizeProductListText(
            inputText: (string) $request->validated('text'),
            locale: $request->validated('locale'),
        );

        return response()->json([
            'data' => $normalized,
        ]);
    }
}
