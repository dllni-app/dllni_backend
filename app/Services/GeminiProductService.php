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

                return null;
            }

            return $this->optimizeGeneratedImageBase64($imageData);
        } catch (Throwable $exception) {
            $this->logFailure(__FUNCTION__, $exception);

            return null;
        }
    }

    /**
     * @return array{items: array<int, string>, normalizedText: string|null}
     */
    public function normalizeProductListText(string $inputText, ?string $locale = null, string $module = 'supermarket'): array
    {
        $normalizedInput = $this->normalizeString($inputText);

        if ($normalizedInput === null) {
            return [
                'items' => [],
                'normalizedText' => null,
            ];
        }

        $payload = $this->generateStructuredTextResponse(
            prompt: $this->buildTextNormalizationPrompt($locale, $module),
            responseSchema: $this->normalizeTextResponseSchema(),
            inputText: $normalizedInput,
            operation: __FUNCTION__,
        );

        $items = [];

        if (is_array($payload) && is_array($payload['items'] ?? null)) {
            foreach ($payload['items'] as $item) {
                $title = $this->normalizeString($item);

                if ($title === null) {
                    continue;
                }

                $items[] = $title;
            }
        }

        if ($items === []) {
            $items = $this->extractFallbackItemsFromText($normalizedInput);
        }

        $uniqueItems = array_values(array_unique($items));

        return [
            'items' => $uniqueItems,
            'normalizedText' => $uniqueItems === [] ? null : implode(' , ', $uniqueItems),
        ];
    }

    /**
     * Interpret free-form user language into a structured smart-search intent.
     *
     * @return array<string, mixed>|null
     */
    public function interpretSmartSearch(string $section, string $query, ?string $locale = 'ar'): ?array
    {
        $section = $section === 'restaurant' ? 'restaurant' : 'supermarket';

        return $this->generateStructuredTextResponse(
            prompt: $this->buildSmartSearchIntentPrompt($section, $locale),
            responseSchema: $section === 'restaurant'
                ? $this->restaurantSmartSearchResponseSchema()
                : $this->supermarketSmartSearchResponseSchema(),
            inputText: $query,
            operation: __FUNCTION__,
        );
    }

    private function buildSmartSearchIntentPrompt(string $section, ?string $locale): string
    {
        $language = $locale === 'en' ? 'English' : 'Arabic, including colloquial Levantine/Syrian Arabic';

        if ($section === 'restaurant') {
            return 'You are an intent parser for restaurant food search. Understand meaning, not keywords. '
                .'Separate filler conversation from useful constraints. Preserve the requested food type: a meal is not '
                .'a sandwich, a drink is not a dessert, and alternatives must not be promoted as exact matches. '
                .'Extract negative constraints, restaurant/cuisine mentions, explicit numeric limits, and soft preferences. '
                .'Use itemType values meal,sandwich,burger,pizza,dish,combo,family_meal,appetizer,side,salad,dessert,drink,breakfast,other or empty string. '
                .'Use goal values find_food,find_restaurant,find_similar_food,browse. '
                .'Return searchText containing only the meaningful food concepts, not filler. '
                .'fastPreparation/lowPrice/highRating/nearby are soft preferences unless an explicit numeric constraint exists. '
                .'Write normalized concepts in the user source language. Input language is '.$language.'.';
        }

        return 'You are an intent parser for supermarket shopping. Understand whether the user asks for direct products, '
            .'a multi-product basket, ingredients to prepare a recipe/meal, a context basket, or a store. '
            .'Do not treat "I want to prepare lasagna" as a request for ready-made lasagna; use goal prepare_recipe. '
            .'Use goal values direct_product_search,multi_product_search,prepare_recipe,prepare_context,find_store,browse. '
            .'For each explicitly requested product extract query, quantity and canonical unit when stated. '
            .'Canonical units: kg,g,l,ml,piece,pack. Use 0 and empty string when quantity/unit are not stated. '
            .'A store mention is preferred by default; storeStrict is true only for wording equivalent to "only from this store". '
            .'sameStoreRequired is true only when the user requires everything from one store. '
            .'For prepare_recipe, set recipeName and also provide inferredIngredients when you know the recipe; '
            .'these are a fallback and a server recipe catalog remains authoritative. '
            .'For prepare_context (breakfast, barbecue, party, school lunch, weekly stock, etc.), set contextName '
            .'and provide a compact core basket in inferredIngredients, normally 5-10 useful items rather than an excessive list. '
            .'Respect already-have and excluded ingredient statements. Input language is '.$language.'.';
    }

    /**
     * @return array<string, mixed>
     */
    private function restaurantSmartSearchResponseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'goal' => ['type' => 'STRING'],
                'searchText' => ['type' => 'STRING'],
                'itemType' => ['type' => 'STRING'],
                'concepts' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'attributes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'excludedAttributes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'excludedItemTypes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'restaurantName' => ['type' => 'STRING'],
                'cuisine' => ['type' => 'STRING'],
                'maxPrice' => ['type' => 'NUMBER'],
                'maxPreparationMinutes' => ['type' => 'INTEGER'],
                'minimumRating' => ['type' => 'NUMBER'],
                'fastPreparation' => ['type' => 'BOOLEAN'],
                'lowPrice' => ['type' => 'BOOLEAN'],
                'highRating' => ['type' => 'BOOLEAN'],
                'nearby' => ['type' => 'BOOLEAN'],
                'confidence' => ['type' => 'NUMBER'],
            ],
            'required' => ['goal', 'searchText', 'concepts', 'attributes', 'excludedAttributes', 'excludedItemTypes', 'fastPreparation', 'lowPrice', 'highRating', 'nearby', 'confidence'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function supermarketSmartSearchResponseSchema(): array
    {
        $item = [
            'type' => 'OBJECT',
            'properties' => [
                'query' => ['type' => 'STRING'],
                'quantity' => ['type' => 'NUMBER'],
                'unit' => ['type' => 'STRING'],
            ],
            'required' => ['query', 'quantity', 'unit'],
        ];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'goal' => ['type' => 'STRING'],
                'searchText' => ['type' => 'STRING'],
                'preferredStoreName' => ['type' => 'STRING'],
                'storeStrict' => ['type' => 'BOOLEAN'],
                'sameStoreRequired' => ['type' => 'BOOLEAN'],
                'recipeName' => ['type' => 'STRING'],
                'contextName' => ['type' => 'STRING'],
                'servings' => ['type' => 'INTEGER'],
                'alreadyHave' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'excludedIngredients' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'items' => ['type' => 'ARRAY', 'items' => $item],
                'inferredIngredients' => ['type' => 'ARRAY', 'items' => $item],
                'confidence' => ['type' => 'NUMBER'],
            ],
            'required' => ['goal', 'searchText', 'storeStrict', 'sameStoreRequired', 'alreadyHave', 'excludedIngredients', 'items', 'inferredIngredients', 'confidence'],
        ];
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
            'Generate a lightweight mobile catalog product image optimized for small upload size.',
            "Product name: {$title}.",
        ];

        $normalizedDescription = $this->normalizeString($description);

        if ($normalizedDescription !== null) {
            $promptLines[] = "Product description: {$normalizedDescription}.";
        }

        $promptLines[] = 'Create a simple compressed 512x512 catalog thumbnail style image. '
            .'Use one centered product only, a plain light studio background, soft lighting, minimal texture, '
            .'minimal shadows, and no extra props or decorative elements. '
            .'Avoid ultra-high detail, complex backgrounds, dense patterns, text, logos, and watermarks. '
            .'Prioritize a small file size and clear product recognition over poster-quality detail. '
            .'Use exact 1:1 aspect ratio.';

        return implode(' ', $promptLines);
    }

    private function buildTextNormalizationPrompt(?string $locale, string $module): string
    {
        $moduleInstruction = in_array($module, ['restaurant', 'resturant'], true)
            ? 'Restaurant module: return prepared dishes or menu items exactly as a customer would order them. For example, "Grilled chicken" should remain "Grilled chicken". '
            : 'Supermarket module: return purchasable grocery products. When the input names a prepared dish or meal, expand it into the ingredients and preparation-kit products needed to make it instead of returning the dish name. For example, "Grilled chicken" should become items such as chicken, grilling spices, cooking oil, and relevant preparation products. ';

        return 'You normalize grocery and restaurant product text. '
            .$moduleInstruction
            .'Given noisy free-form input, extract only product names and return canonical names as JSON. '
            .'Return one object with exactly one field: "items" (array of strings). '
            .'Remove quantities, units, numbers, and filler words. '
            .'Fix obvious misspellings when confidence is high. '
            .'Keep original language; for Arabic normalize variants to common market wording when possible. '
            .'Do not include duplicates and keep input order. '
            .'Output language should be '
            .($locale === 'en' ? 'English where source is English, otherwise source language' : 'source language, especially Arabic when input is Arabic')
            .'.';
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
     * @return array<string, mixed>
     */
    private function normalizeTextResponseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'items' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'STRING'],
                ],
            ],
            'required' => ['items'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function generateStructuredTextResponse(
        string $prompt,
        array $responseSchema,
        string $inputText,
        string $operation,
    ): ?array {
        try {
            $model = (string) (config('gemini.text_model') ?: config('gemini.vision_model'));
            $payload = [
                'contents' => [[
                    'parts' => [
                        ['text' => $prompt],
                        ['text' => $inputText],
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

    private function optimizeGeneratedImageBase64(string $base64Image): string
    {
        if (
            ! function_exists('imagecreatefromstring')
            || ! function_exists('imagecreatetruecolor')
            || ! function_exists('imagecopyresampled')
            || ! function_exists('imagejpeg')
        ) {
            return $base64Image;
        }

        $binary = base64_decode($base64Image, true);

        if ($binary === false || $binary === '') {
            return $base64Image;
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            return $base64Image;
        }

        $canvas = false;

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            if ($width <= 0 || $height <= 0) {
                return $base64Image;
            }

            $maxDimension = 768;
            $scale = min(1.0, $maxDimension / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($canvas === false) {
                return $base64Image;
            }

            $white = imagecolorallocate($canvas, 255, 255, 255);

            if ($white !== false) {
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
            }

            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $width,
                $height,
            );

            ob_start();
            $success = imagejpeg($canvas, null, 82);
            $optimizedBinary = ob_get_clean();

            if (! $success || ! is_string($optimizedBinary) || $optimizedBinary === '') {
                return $base64Image;
            }

            if (mb_strlen($optimizedBinary) >= mb_strlen($binary)) {
                return $base64Image;
            }

            return base64_encode($optimizedBinary);
        } finally {
            imagedestroy($source);

            if ($canvas !== false) {
                imagedestroy($canvas);
            }
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

    /**
     * @return array<int, string>
     */
    private function extractFallbackItemsFromText(string $inputText): array
    {
        $lines = preg_split('/[\r\n,;،]+/u', $inputText) ?: [];
        $items = [];

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            $clean = preg_replace('/\b\d+(?:[\.,]\d+)?\b/u', ' ', $line);
            $clean = is_string($clean) ? $clean : $line;
            $clean = preg_replace('/\b(kg|كيلو|كغ|غم|جرام|غرام|حبة|حبات|قطعة|علبة|pack|pcs?)\b/iu', ' ', $clean);
            $clean = is_string($clean) ? $clean : $line;
            $clean = $this->normalizeString($clean);

            if ($clean === null) {
                continue;
            }

            $items[] = $clean;
        }

        return $items;
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
        $url = "{$baseUrl}/models/{$model}:generateContent";

        $response = Http::timeout((int) config('gemini.timeout'))
            ->retry(
                (int) config('gemini.retry_times'),
                (int) config('gemini.retry_sleep'),
                when: null,
                throw: false,
            )
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ])
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
