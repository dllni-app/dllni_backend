<?php

declare(strict_types=1);

namespace App\Services;

use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Data\ImageConfig;
use Gemini\Data\Schema;
use Gemini\Enums\DataType;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
use Gemini\Laravel\Facades\Gemini;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GeminiProductService
{
    private const VISION_MODEL = 'gemini-2.0-flash';

    private const IMAGE_MODEL = 'gemini-2.5-flash-image';

    /**
     * Analyze an uploaded product image and return a structured title and description.
     *
     * @return array{title: string|null, description: string|null}
     */
    public function extractProductFromImageFile(UploadedFile $file, ?string $locale = null): array
    {
        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));

        return $this->extractProductFromImage($base64, $locale);
    }

    /**
     * Analyze a single product image (base64) and return a structured title and description.
     *
     * @return array{title: string|null, description: string|null}
     */
    public function extractProductFromImage(string $base64Image, ?string $locale = null): array
    {
        $prompt = 'You are a product catalog assistant. Analyze this product image and return a JSON object '
            .'with exactly two fields: "title" and "description". '
            .'The title should be short and suitable for use in a product list. '
            .'The description should be a concise marketing description in '
            .($locale === 'ar' ? 'Arabic' : 'the main language of the packaging')
            .'. Do not include prices, sizes, or ingredients unless they are essential.';

        try {
            $generationConfig = new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                responseSchema: new Schema(
                    type: DataType::OBJECT,
                    properties: [
                        'title' => new Schema(type: DataType::STRING),
                        'description' => new Schema(type: DataType::STRING),
                    ],
                    required: ['title', 'description'],
                ),
            );

            $result = Gemini::generativeModel(model: self::VISION_MODEL)
                ->withGenerationConfig($generationConfig)
                ->generateContent([
                    $prompt,
                    new Blob(
                        mimeType: MimeType::IMAGE_JPEG,
                        data: $base64Image
                    ),
                ]);

            /** @var array<string, mixed>|null $json */
            $json = $result->json();

            return [
                'title' => isset($json['title']) ? (string) $json['title'] : null,
                'description' => isset($json['description']) ? (string) $json['description'] : null,
            ];
        } catch (Throwable $exception) {
            Log::error('Gemini extractProductFromImage failed', [
                'exception' => $exception,
            ]);

            return [
                'title' => null,
                'description' => null,
            ];
        }
    }

    /**
     * Analyze an uploaded menu image and return an array of items with titles and descriptions.
     *
     * @return array<int, array{title: string, description: string|null}>
     */
    public function extractMenuFromImageFile(UploadedFile $file, ?string $locale = null): array
    {
        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));

        return $this->extractMenuFromImage($base64, $locale);
    }

    /**
     * Analyze a menu/price-list image (base64) and return an array of items with titles and descriptions.
     *
     * @return array<int, array{title: string, description: string|null}>
     */
    public function extractMenuFromImage(string $base64Image, ?string $locale = null): array
    {
        $prompt = 'You are digitizing a restaurant or supermarket menu from a photo. '
            .'Identify individual products or dishes and return them as structured JSON. '
            .'Return an object with a single field "items", which is an array of objects with '
            .'"title" and "description" fields. '
            .'Do not include prices, allergens, or categories. '
            .'Write titles and descriptions in '
            .($locale === 'ar' ? 'Arabic' : 'the main language of the menu')
            .'.';

        try {
            $generationConfig = new GenerationConfig(
                responseMimeType: ResponseMimeType::APPLICATION_JSON,
                responseSchema: new Schema(
                    type: DataType::OBJECT,
                    properties: [
                        'items' => new Schema(
                            type: DataType::ARRAY,
                            items: new Schema(
                                type: DataType::OBJECT,
                                properties: [
                                    'title' => new Schema(type: DataType::STRING),
                                    'description' => new Schema(type: DataType::STRING),
                                ],
                                required: ['title'],
                            )
                        ),
                    ],
                    required: ['items'],
                ),
            );

            $result = Gemini::generativeModel(model: self::VISION_MODEL)
                ->withGenerationConfig($generationConfig)
                ->generateContent([
                    $prompt,
                    new Blob(
                        mimeType: MimeType::IMAGE_JPEG,
                        data: $base64Image
                    ),
                ]);

            /** @var array<string, mixed>|null $json */
            $json = $result->json();

            /** @var array<int, array<string, mixed>> $items */
            $items = Arr::get($json, 'items', []);

            return Collection::make($items)
                ->map(static function (array $item): array {
                    $title = mb_trim((string) ($item['title'] ?? ''));
                    $description = $item['description'] ?? null;

                    return [
                        'title' => $title,
                        'description' => $description !== null ? (string) $description : null,
                    ];
                })
                ->filter(static fn (array $item): bool => $item['title'] !== '')
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::error('Gemini extractMenuFromImage failed', [
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /**
     * Generate a product image as a base64-encoded PNG from a title and description.
     */
    public function generateProductImage(string $title, ?string $description = null): ?string
    {
        $promptLines = [
            'Generate a high-quality catalog product photo.',
            "Product name: {$title}.",
        ];

        if ($description !== null && $description !== '') {
            $promptLines[] = "Product description: {$description}.";
        }

        $promptLines[] = 'Use a clean studio background with soft lighting. '
            .'Realistic style, no text, no watermarks, centered composition, square aspect ratio.';

        $prompt = implode(' ', $promptLines);

        try {
            $imageConfig = new ImageConfig(aspectRatio: '1:1');
            $generationConfig = new GenerationConfig(imageConfig: $imageConfig);

            $response = Gemini::generativeModel(model: self::IMAGE_MODEL)
                ->withGenerationConfig($generationConfig)
                ->generateContent($prompt);

            $parts = $response->parts();

            if ($parts === [] || $parts[0]->inlineData === null) {
                return null;
            }

            return $parts[0]->inlineData->data;
        } catch (Throwable $exception) {
            Log::error('Gemini generateProductImage failed', [
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
