<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\ProductAi\ExtractFromMenuImageRequest;
use App\Http\Requests\ProductAi\ExtractFromProductImageRequest;
use App\Http\Requests\ProductAi\GenerateProductImageRequest;
use App\Services\GeminiProductService;
use Illuminate\Http\JsonResponse;

final class ProductAiController
{
    public function __construct(
        private GeminiProductService $gemini,
    ) {}

    public function extractFromImage(ExtractFromProductImageRequest $request): JsonResponse
    {
        $data = $this->gemini->extractProductFromImageFile(
            $request->file('image'),
            $request->validated('locale'),
        );

        return response()->json(['data' => $data]);
    }

    public function extractFromMenu(ExtractFromMenuImageRequest $request): JsonResponse
    {
        $items = $this->gemini->extractMenuFromImageFile(
            $request->file('image'),
            $request->validated('locale'),
        );

        return response()->json(['data' => ['items' => $items]]);
    }

    public function generateImage(GenerateProductImageRequest $request): JsonResponse
    {
        $imageBase64 = $this->gemini->generateProductImage(
            $request->validated('title'),
            $request->validated('description'),
        );

        return response()->json(['data' => ['imageBase64' => $imageBase64]]);
    }
}
