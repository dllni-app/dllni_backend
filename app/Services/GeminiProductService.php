<?php

declare(strict_types=1);

namespace App\Services;

use Gemini\Data\Blob;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Schema;
use Gemini\Enums\DataType;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
use Gemini\Enums\ResponseModality;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Responses\GenerativeModel\GenerateContentResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class GeminiProductService
{
    private const VISION_MODEL = 'gemini-2.5-flash';

    private const IMAGE_MODEL = 'gemini-2.5-flash';

    /**
     * Analyze an uploaded product image and return a structured title and description.
     *
     * @return array{title: string|null, description: string|null}
     */
    public function extractProductFromImageFile(UploadedFile $file, ?string $locale = null): array
    {
        try {
            return $this->extractProductFromImage(
                base64Image: $this->encodeUploadedFile($file),
                locale: $locale,
                mimeType: $this->resolveMimeType($file->getMimeType()),
            );
        } catch (Throwable $exception) {
            $this->logFailure(__FUNCTION__, $exception);

            return [
                'title' => null,
                'description' => null,
            ];
        }
    }

    /**
     * Analyze a single product image (base64) and return a structured title and description.
     *
     * @return array{title: string|null, description: string|null}
     */
    public function extractProductFromImage(
        string $base64Image,
        ?string $locale = null,
        MimeType $mimeType = MimeType::IMAGE_JPEG,
    ): array {
        $payload = $this->generateStructuredVisionResponse(
            prompt: $this->buildProductPrompt($locale),
            schema: $this->productSchema(),
            base64Image: $base64Image,
            mimeType: $mimeType,
            operation: __FUNCTION__,
        );

        if (! is_array($payload)) {
            return [
                'title' => null,
                'description' => null,
            ];
        }

        return [
            'title' => $this->normalizeString($payload['title'] ?? null),
            'description' => $this->normalizeString($payload['description'] ?? null),
        ];
    }

    /**
     * Analyze an uploaded menu image and return an array of items with titles and descriptions.
     *
     * @return array<int, array{title: string, description: string|null}>
     */
    public function extractMenuFromImageFile(UploadedFile $file, ?string $locale = null): array
    {
        try {
            return $this->extractMenuFromImage(
                base64Image: $this->encodeUploadedFile($file),
                locale: $locale,
                mimeType: $this->resolveMimeType($file->getMimeType()),
            );
        } catch (Throwable $exception) {
            $this->logFailure(__FUNCTION__, $exception);

            return [];
        }
    }

    /**
     * Analyze a menu/price-list image (base64) and return an array of items with titles and descriptions.
     *
     * @return array<int, array{title: string, description: string|null}>
     */
    public function extractMenuFromImage(
        string $base64Image,
        ?string $locale = null,
        MimeType $mimeType = MimeType::IMAGE_JPEG,
    ): array {
        $payload = $this->generateStructuredVisionResponse(
            prompt: $this->buildMenuPrompt($locale),
            schema: $this->menuSchema(),
            base64Image: $base64Image,
            mimeType: $mimeType,
            operation: __FUNCTION__,
        );

        if (! is_array($payload) || ! is_array($payload['items'] ?? null)) {
            return [];
        }

        $items = [];

        foreach ($payload['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->normalizeString($item['title'] ?? null);

            if ($title === null) {
                continue;
            }

            $items[] = [
                'title' => $title,
                'description' => $this->normalizeString($item['description'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * Generate a product image as a base64-encoded PNG from a title and description.
     */
    public function generateProductImage(string $title, ?string $description = null): ?string
    {
        try {
            $response = Gemini::generativeModel(model: self::IMAGE_MODEL)
                ->withGenerationConfig($this->imageGenerationConfig())
                ->generateContent($this->buildImagePrompt($title, $description));

            $imageData = $this->extractInlineImageData($response);

            if ($imageData === null) {
                $firstCandidate = $response->candidates[0] ?? null;

                Log::warning('Gemini generateProductImage returned no inline image data', [
                    'model' => self::IMAGE_MODEL,
                    'finish_reason' => $firstCandidate?->finishReason?->value,
                    'response_text' => $this->extractResponseText($response),
                ]);
            }

            return $imageData;
        } catch (Throwable $exception) {
            $this->logFailure(__FUNCTION__, $exception);

            return null;
        }
    }

    private function buildProductPrompt(?string $locale): string
    {
        return 'You are a product catalog assistant. Analyze this product image and return a JSON object '
            .'with exactly two fields: "title" and "description". '
            .'The title should be short and suitable for use in a product list. '
            .'The description should be a concise marketing description in '
            .($locale === 'ar' ? 'Arabic' : 'the main language of the packaging')
            .'. Do not include prices, sizes, or ingredients unless they are essential.';
    }

    private function buildMenuPrompt(?string $locale): string
    {
        return 'You are digitizing a restaurant or supermarket menu from a photo. '
            .'Identify individual products or dishes and return them as structured JSON. '
            .'Return an object with a single field "items", which is an array of objects with '
            .'"title" and "description" fields. '
            .'Do not include prices, allergens, or categories. '
            .'Write titles and descriptions in '
            .($locale === 'ar' ? 'Arabic' : 'the main language of the menu')
            .'.';
    }

    private function buildImagePrompt(string $title, ?string $description): string
    {
        $promptLines = [
            'Generate a high-quality catalog product photo.',
            "Product name: {$title}.",
        ];

        $normalizedDescription = $this->normalizeString($description);

        if ($normalizedDescription !== null) {
            $promptLines[] = "Product description: {$normalizedDescription}.";
        }

        $promptLines[] = 'Use a clean studio background with soft lighting. '
            .'Realistic style, no text, no watermarks, centered composition, and exact 1:1 aspect ratio.';

        return implode(' ', $promptLines);
    }

    private function productSchema(): Schema
    {
        return new Schema(
            type: DataType::OBJECT,
            properties: [
                'title' => new Schema(type: DataType::STRING),
                'description' => new Schema(type: DataType::STRING),
            ],
            required: ['title', 'description'],
        );
    }

    private function menuSchema(): Schema
    {
        return new Schema(
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
                    ),
                ),
            ],
            required: ['items'],
        );
    }

    private function jsonGenerationConfig(Schema $schema): GenerationConfig
    {
        return new GenerationConfig(
            responseMimeType: ResponseMimeType::APPLICATION_JSON,
            responseSchema: $schema,
        );
    }

    private function imageGenerationConfig(): GenerationConfig
    {
        return new GenerationConfig(responseModalities: [ResponseModality::IMAGE]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function generateStructuredVisionResponse(
        string $prompt,
        Schema $schema,
        string $base64Image,
        MimeType $mimeType,
        string $operation,
    ): ?array {
        try {
            $response = Gemini::generativeModel(model: self::VISION_MODEL)
                ->withGenerationConfig($this->jsonGenerationConfig($schema))
                ->generateContent([
                    $prompt,
                    new Blob(mimeType: $mimeType, data: $base64Image),
                ]);

            $payload = $response->json(true, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : null;
        } catch (Throwable $exception) {
            $this->logFailure($operation, $exception);

            return null;
        }
    }

    private function extractInlineImageData(GenerateContentResponse $response): ?string
    {
        foreach ($response->parts() as $part) {
            if ($part->inlineData !== null) {
                return $part->inlineData->data;
            }
        }

        return null;
    }

    private function extractResponseText(GenerateContentResponse $response): ?string
    {
        try {
            return $response->text();
        } catch (Throwable) {
            return null;
        }
    }

    private function encodeUploadedFile(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Unable to access uploaded file path.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read uploaded file contents.');
        }

        return base64_encode($contents);
    }

    private function resolveMimeType(?string $mimeType): MimeType
    {
        return MimeType::tryFrom((string) $mimeType) ?? MimeType::IMAGE_JPEG;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = mb_trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function logFailure(string $operation, Throwable $exception): void
    {
        Log::error("Gemini {$operation} failed", [
            'model' => $operation === 'generateProductImage' ? self::IMAGE_MODEL : self::VISION_MODEL,
            'exception' => $exception,
        ]);
    }
}
