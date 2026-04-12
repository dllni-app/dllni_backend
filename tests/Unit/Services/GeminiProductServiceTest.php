<?php

declare(strict_types=1);

use App\Services\GeminiProductService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Config::set('gemini.api_key', 'test-api-key');
    Config::set('gemini.vision_model', 'gemini-test-vision');
    Config::set('gemini.image_gen_model', 'gemini-test-image');
});

it('extracts product details from an uploaded image', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => '{"title":"  Fresh Milk  ","description":"  Rich and creamy milk.  "}',
                    ]],
                ],
            ]],
        ], 200),
    ]);

    $service = app(GeminiProductService::class);
    $file = UploadedFile::fake()->image('product.png');

    expect($service->extractProductFromImageFile($file))
        ->toBe([
            'title' => 'Fresh Milk',
            'description' => 'Rich and creamy milk.',
        ]);

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return ($data['contents'][0]['parts'][1]['inline_data']['mime_type'] ?? null) === 'image/png';
    });
});

it('extracts menu items and filters blank titles', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => '{"items":[{"title":"  Burger  ","description":"  Flame grilled beef.  "},{"title":"   ","description":"Ignore me"},{"title":"Fries","description":""},{"title":12,"description":34}]}',
                    ]],
                ],
            ]],
        ], 200),
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
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Generated image attached.'],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/png',
                                'data' => 'encoded-image-data',
                            ],
                        ],
                    ],
                ],
            ]],
        ], 200),
    ]);

    $service = app(GeminiProductService::class);

    expect($service->generateProductImage('Orange Juice', 'Cold pressed'))
        ->toBe('encoded-image-data');
});

it('returns safe fallbacks when gemini throws', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response('error', 500),
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
