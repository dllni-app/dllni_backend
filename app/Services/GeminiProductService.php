<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GeminiApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

final class GeminiProductService
{
    /**
     * @return array{title: string|null, description: string|null}
     */
    public function extractProductFromImageFile(UploadedFile $file, ?string $locale = null): array
    {
        try {
            return $this->extractProductFromImage(
                base64Image: $this->encodeUploadedFile($file),
                locale: $locale,
                mimeType: $this->resolveMimeTypeString($file->getMimeType()),
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
     * @return array{title: string|null, description: string|null}
     */
    public function extractProductFromImage(
        string $base64Image,
        ?string $locale = null,
        string $mimeType = 'image/jpeg',
    ): array {
        $payload = $this->generateStructuredVisionResponse(
            prompt: $this->buildProductPrompt($locale),
            responseSchema: $this->productResponseSchema(),
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
     * @return array<int, array{title: string, description: string|null}>
     */
    public function extractMenuFromImageFile(UploadedFile $file, ?string $locale = null): array
    {
        try {
            return $this->extractMenuFromImage(
                base64Image: $this->encodeUploadedFile($file),
                locale: $locale,
                mimeType: $this->resolveMimeTypeString($file->getMimeType()),
            );
        } catch (Throwable $exception) {
            $this->logFailure(__FUNCTION__, $exception);

            return [];
        }
    }

    /**
     * @return array<int, array{title: string, description: string|null}>
     */
    public function extractMenuFromImage(
        string $base64Image,
        ?string $locale = null,
        string $mimeType = 'image/jpeg',
    ): array {
        $payload = $this->generateStructuredVisionResponse(
            prompt: $this->buildMenuPrompt($locale),
            responseSchema: $this->menuResponseSchema(),
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

    public function generateProductImage(string $title, ?string $description = null): ?string
    {
        try {
            $model = (string) config('gemini.image_gen_model');
            $payload = [
                'contents' => [[
                    'parts' => [
                        ['text' => $this->buildImagePrompt($title, $description)],
                    ],
                ]],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE'],
                    'imageConfig' => [
                        'aspectRatio' => '1:1',
                        'imageSize' => '1K',
                    ],
                ],
            ];

            $response = $this->postGeminiGenerateContent($model, $payload);
            $imageData = $this->extractInlineImageDataFromResponse($response);

            if ($imageData === null) {
                $firstCandidate = is_array($response['candidates'][0] ?? null)
                    ? $response['candidates'][0]
                    : null;

                Log::warning('Gemini generateProductImage returned no inline image data', [
                    'model' => $model,
                    'finish_reason' => is_array($firstCandidate)
                        ? ($firstCandidate['finishReason'] ?? $firstCandidate['finish_reason'] ?? null)
                        : null,
                    'response_text' => $this->extractResponseTextFromDecoded($response),
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

    /**
     * @return array<string, mixed>
     */
    private function productResponseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'title' => ['type' => 'STRING'],
                'description' => ['type' => 'STRING'],
            ],
            'required' => ['title', 'description'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function menuResponseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'items' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'title' => ['type' => 'STRING'],
                            'description' => ['type' => 'STRING'],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function generateStructuredVisionResponse(
        string $prompt,
        array $responseSchema,
        string $base64Image,
        string $mimeType,
        string $operation,
    ): ?array {
        try {
            $model = (string) config('gemini.vision_model');
            $payload = [
                'contents' => [[
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Image,
                            ],
                        ],
                    ],
                ]],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                    'response_schema' => $responseSchema,
                ],
            ];

            $response = $this->postGeminiGenerateContent($model, $payload);
            $jsonText = $this->extractResponseTextFromDecoded($response);

            if ($jsonText === null || $jsonText === '') {
                return null;
            }

            try {
                $payloadDecoded = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }

            return is_array($payloadDecoded) ? $payloadDecoded : null;
        } catch (Throwable $exception) {
            $this->logFailure($operation, $exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractInlineImageDataFromResponse(array $response): ?string
    {
        $candidates = $response['candidates'] ?? [];

        if (! is_array($candidates)) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $parts = $candidate['content']['parts'] ?? [];

            if (! is_array($parts)) {
                continue;
            }

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $inline = $part['inline_data'] ?? $part['inlineData'] ?? null;

                if (is_array($inline) && isset($inline['data']) && is_string($inline['data']) && $inline['data'] !== '') {
                    return $inline['data'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractResponseTextFromDecoded(array $response): ?string
    {
        $candidates = $response['candidates'] ?? [];

        if (! is_array($candidates)) {
            return null;
        }

        $textParts = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $parts = $candidate['content']['parts'] ?? [];

            if (! is_array($parts)) {
                continue;
            }

            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                    $textParts[] = $part['text'];
                }
            }
        }

        if ($textParts === []) {
            return null;
        }

        return implode('', $textParts);
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

    private function resolveMimeTypeString(?string $mimeType): string
    {
        $allowed = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'image/heic',
            'image/heif',
        ];

        $mime = is_string($mimeType) ? mb_strtolower($mimeType) : '';

        return in_array($mime, $allowed, true) ? $mime : 'image/jpeg';
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
            'model' => $operation === 'generateProductImage'
                ? config('gemini.image_gen_model')
                : config('gemini.vision_model'),
            'exception' => $exception,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postGeminiGenerateContent(string $model, array $payload): array
    {
        $baseUrl = mb_rtrim((string) config('gemini.base_url'), '/');
        $apiKey = (string) config('gemini.api_key');
        $url = "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout((int) config('gemini.timeout'))
            ->retry(
                (int) config('gemini.retry_times'),
                (int) config('gemini.retry_sleep'),
                when: null,
                throw: false,
            )
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $payload);

        if ($response->failed()) {
            throw new GeminiApiException(
                "Gemini API error [{$response->status()}]: ".$response->body(),
                $response->status(),
            );
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new GeminiApiException('Gemini API returned an invalid JSON body.', $response->status());
        }

        return $decoded;
    }
}
