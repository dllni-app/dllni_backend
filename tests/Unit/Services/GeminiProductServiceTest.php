<?php

declare(strict_types=1);

use App\Services\GeminiProductService;
use Gemini\Data\Blob;
use Gemini\Enums\MimeType;
use Gemini\Laravel\Facades\Gemini;
use Gemini\Resources\GenerativeModel;
use Gemini\Responses\GenerativeModel\GenerateContentResponse;
use Illuminate\Http\UploadedFile;

it('extracts product details from an uploaded image', function (): void {
    Gemini::fake([
        GenerateContentResponse::fake([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => '{"title":"  Fresh Milk  ","description":"  Rich and creamy milk.  "}',
                    ]],
                ],
            ]],
        ]),
    ]);

    $service = app(GeminiProductService::class);
    $file = UploadedFile::fake()->image('product.png');

    expect($service->extractProductFromImageFile($file))
        ->toBe([
            'title' => 'Fresh Milk',
            'description' => 'Rich and creamy milk.',
        ]);

    Gemini::assertSent(
        resource: GenerativeModel::class,
        callback: function (string $method, array $parameters): bool {
            if ($method !== 'generateContent' || ! isset($parameters[0][1])) {
                return false;
            }

            return $parameters[0][1] instanceof Blob
                && $parameters[0][1]->mimeType === MimeType::IMAGE_PNG;
        },
    );
});

it('extracts menu items and filters blank titles', function (): void {
    Gemini::fake([
        GenerateContentResponse::fake([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => '{"items":[{"title":"  Burger  ","description":"  Flame grilled beef.  "},{"title":"   ","description":"Ignore me"},{"title":"Fries","description":""},{"title":12,"description":34}]}',
                    ]],
                ],
            ]],
        ]),
    ]);

    $service = app(GeminiProductService::class);

    expect($service->extractMenuFromImage('base64-image'))
        ->toBe([
            [
                'title' => 'Burger',
                'description' => 'Flame grilled beef.',
            ],
            [
                'title' => 'Fries',
                'description' => null,
            ],
            [
                'title' => '12',
                'description' => '34',
            ],
        ]);
});

it('returns the first inline image part from image generation responses', function (): void {
    Gemini::fake([
        GenerateContentResponse::fake([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Generated image attached.'],
                        ['inlineData' => ['mimeType' => 'image/png', 'data' => 'encoded-image-data']],
                    ],
                ],
            ]],
        ]),
    ]);

    $service = app(GeminiProductService::class);

    expect($service->generateProductImage('Orange Juice', 'Cold pressed'))
        ->toBe('encoded-image-data');
});

it('returns safe fallbacks when gemini throws', function (): void {
    Gemini::fake([
        new RuntimeException('Gemini exploded.'),
        new RuntimeException('Gemini exploded again.'),
        new RuntimeException('Gemini exploded for images.'),
    ]);

    $service = app(GeminiProductService::class);

    expect($service->extractProductFromImage('base64-image'))
        ->toBe([
            'title' => null,
            'description' => null,
        ]);

    expect($service->extractMenuFromImage('base64-image'))->toBe([]);
    expect($service->generateProductImage('Tea'))->toBeNull();
});
