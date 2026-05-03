<?php

declare(strict_types=1);

use App\Services\GeminiProductService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Config::set('gemini.api_key', 'test-api-key');
    Config::set('gemini.vision_model', 'gemini-test-vision');
    Config::set('gemini.text_model', 'gemini-test-text');
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
        $url = $request->url();

        return ($data['contents'][0]['parts'][1]['inline_data']['mime_type'] ?? null) === 'image/png'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && ! str_contains($url, '?key=')
            && ! str_contains($url, '&key=');
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

    Http::assertSent(function ($request): bool {
        $url = $request->url();

        return $request->hasHeader('x-goog-api-key', 'test-api-key')
            && ! str_contains($url, '?key=')
            && ! str_contains($url, '&key=');
    });
});

it('uses restaurant module instructions when normalizing product text', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => '{"items":["Grilled chicken"]}',
                    ]],
                ],
            ]],
        ], 200),
    ]);

    $service = app(GeminiProductService::class);

    expect($service->normalizeProductListText('I want grilled chicken', 'en', 'resturant'))
        ->toBe([
            'items' => ['Grilled chicken'],
            'normalizedText' => 'Grilled chicken',
        ]);

    Http::assertSent(function ($request): bool {
        $data = $request->data();
        $prompt = $data['contents'][0]['parts'][0]['text'] ?? '';

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-text:generateContent'
            && is_string($prompt)
            && str_contains($prompt, 'Restaurant module')
            && str_contains($prompt, 'Grilled chicken" should remain "Grilled chicken');
    });
});

it('uses supermarket module instructions when normalizing product text', function (): void {
    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => '{"items":["Chicken","Grilling spices","Cooking oil"]}',
                    ]],
                ],
            ]],
        ], 200),
    ]);

    $service = app(GeminiProductService::class);

    expect($service->normalizeProductListText('I want grilled chicken', 'en', 'supermarket'))
        ->toBe([
            'items' => ['Chicken', 'Grilling spices', 'Cooking oil'],
            'normalizedText' => 'Chicken , Grilling spices , Cooking oil',
        ]);

    Http::assertSent(function ($request): bool {
        $data = $request->data();
        $prompt = $data['contents'][0]['parts'][0]['text'] ?? '';

        return is_string($prompt)
            && str_contains($prompt, 'Supermarket module')
            && str_contains($prompt, 'expand it into the ingredients and preparation-kit products');
    });
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
