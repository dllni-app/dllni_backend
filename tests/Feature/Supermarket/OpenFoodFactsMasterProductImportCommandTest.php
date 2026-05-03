<?php

declare(strict_types=1);

use App\Models\MasterProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function openFoodFactsJsonlPath(array $lines, bool $gzip = false): string
{
    $tempPath = tempnam(sys_get_temp_dir(), 'off-test-');
    if ($tempPath === false) {
        throw new RuntimeException('Failed to create temp file for test fixture.');
    }

    $targetPath = $tempPath.($gzip ? '.jsonl.gz' : '.jsonl');
    @unlink($tempPath);

    $normalizedLines = array_map(static function (mixed $line): string {
        if (is_string($line)) {
            return $line;
        }

        return json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }, $lines);

    $content = implode(PHP_EOL, $normalizedLines).PHP_EOL;

    if ($gzip) {
        file_put_contents($targetPath, gzencode($content, 9));
    } else {
        file_put_contents($targetPath, $content);
    }

    return $targetPath;
}

function validImageBytes(string $name = 'image.png'): string
{
    $file = UploadedFile::fake()->image($name, 40, 40);
    $bytes = file_get_contents($file->getRealPath());

    if (! is_string($bytes) || $bytes === '') {
        throw new RuntimeException('Failed to generate valid image bytes for test.');
    }

    return $bytes;
}

it('imports syrian products from jsonl and skips non syrian rows', function (): void {
    $path = openFoodFactsJsonlPath([
        [
            'code' => '9900000000011',
            'product_name_ar' => 'شاي أسود',
            'product_name' => 'Black Tea',
            'brands' => 'Al-Brand',
            'generic_name_ar' => 'شاي أسود ممتاز',
            'product_quantity_unit' => 'g',
            'quantity' => '500 g',
            'last_modified_t' => 1_710_000_000,
            'countries_tags' => ['en:syria', 'en:lebanon'],
        ],
        [
            'code' => '9900000000012',
            'product_name' => 'Not Syrian Product',
            'brands' => 'Other Brand',
            'countries_tags' => ['en:france'],
        ],
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $path,
        '--country' => 'en:syria',
        '--skip-images' => true,
    ])->assertSuccessful();

    expect(MasterProduct::query()->count())->toBe(1);

    $product = MasterProduct::query()->firstOrFail();
    expect($product->barcode)->toBe('9900000000011');
    expect($product->name)->toBe('شاي أسود');
    expect($product->brand)->toBe('Al-Brand');
    expect($product->description)->toBe('شاي أسود ممتاز');
    expect($product->unit?->value ?? $product->unit)->toBe('gram');
    expect($product->openfoodfacts_url)->toBe('https://world.openfoodfacts.org/product/9900000000011');
    expect($product->openfoodfacts_last_modified_at)->not->toBeNull();
    expect($product->openfoodfacts_countries_tags)->toContain('en:syria');
    expect($product->openfoodfacts_payload_hash)->not->toBeNull();
});

it('refreshes openfoodfacts fields on reimport without creating duplicates', function (): void {
    $firstPath = openFoodFactsJsonlPath([
        [
            'code' => '9900000000021',
            'product_name' => 'Old Name',
            'brands' => 'Brand One',
            'product_quantity_unit' => 'kg',
            'quantity' => '1 kg',
            'last_modified_t' => 1_710_000_000,
            'countries_tags' => ['en:syria'],
        ],
    ]);

    $secondPath = openFoodFactsJsonlPath([
        [
            'code' => '9900000000021',
            'product_name' => 'New Name',
            'brands' => 'Brand Two',
            'product_quantity_unit' => 'kg',
            'quantity' => '2 kg',
            'last_modified_t' => 1_720_000_000,
            'countries_tags' => ['en:syria'],
        ],
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $firstPath,
        '--skip-images' => true,
    ])->assertSuccessful();

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $secondPath,
        '--skip-images' => true,
    ])->assertSuccessful();

    expect(MasterProduct::query()->count())->toBe(1);

    $product = MasterProduct::query()->where('barcode', '9900000000021')->firstOrFail();
    expect($product->name)->toBe('New Name');
    expect($product->brand)->toBe('Brand Two');
    expect($product->description)->toBe('New Name 2 kg');
});

it('supports dry run without database or media writes', function (): void {
    $path = openFoodFactsJsonlPath([
        [
            'code' => '9900000000031',
            'product_name' => 'Dry Run Product',
            'brands' => 'Dry Brand',
            'countries_tags' => ['en:syria'],
            'image_url' => 'https://images.example/dry-run.png',
        ],
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $path,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(MasterProduct::query()->where('barcode', '9900000000031')->exists())->toBeFalse();
});

it('counts bad json and missing barcode or name rows while continuing import', function (): void {
    $path = openFoodFactsJsonlPath([
        '{"broken": ',
        [
            'product_name' => 'Missing Barcode',
            'countries_tags' => ['en:syria'],
        ],
        [
            'code' => '9900000000042',
            'countries_tags' => ['en:syria'],
        ],
        [
            'code' => '9900000000043',
            'product_name' => 'Valid Product',
            'countries_tags' => ['en:syria'],
        ],
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $path,
        '--skip-images' => true,
    ])
        ->expectsOutputToContain('JSON parse errors: 1')
        ->expectsOutputToContain('skipped missing barcode/name: 2')
        ->assertSuccessful();

    expect(MasterProduct::query()->count())->toBe(1);
    expect(MasterProduct::query()->where('barcode', '9900000000043')->exists())->toBeTrue();
});

it('imports and stores primary image media when image download is valid', function (): void {
    $path = openFoodFactsJsonlPath([
        [
            'code' => '9900000000051',
            'product_name' => 'Image Product',
            'countries_tags' => ['en:syria'],
            'selected_images' => [
                'front' => [
                    'display' => [
                        'ar' => 'https://images.example/front-ar.png',
                    ],
                ],
            ],
        ],
    ]);

    $imageBytes = validImageBytes('off-primary.png');

    Http::fake([
        'https://images.example/*' => Http::response($imageBytes, 200, ['Content-Type' => 'image/png']),
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $path,
    ])->assertSuccessful();

    $product = MasterProduct::query()->where('barcode', '9900000000051')->firstOrFail();
    $media = $product->getFirstMedia(MasterProduct::IMAGE_COLLECTION);

    expect($media)->not->toBeNull();
    expect((string) $media?->file_name)->toStartWith('off-9900000000051.');
    expect($media?->getCustomProperty('source'))->toBe('openfoodfacts');
    expect($media?->getCustomProperty('barcode'))->toBe('9900000000051');
});

it('does not fail the product import when image download fails', function (): void {
    $path = openFoodFactsJsonlPath([
        [
            'code' => '9900000000061',
            'product_name' => 'Image Failure Product',
            'countries_tags' => ['en:syria'],
            'image_url' => 'https://images.example/fail.png',
        ],
    ]);

    Http::fake([
        'https://images.example/*' => Http::response('', 500),
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $path,
    ])->assertSuccessful();

    $product = MasterProduct::query()->where('barcode', '9900000000061')->firstOrFail();
    expect($product->getFirstMedia(MasterProduct::IMAGE_COLLECTION))->toBeNull();
});

it('skips unsupported mime types and oversized images', function (): void {
    $unsupportedPath = openFoodFactsJsonlPath([
        [
            'code' => '9900000000071',
            'product_name' => 'Unsupported Mime',
            'countries_tags' => ['en:syria'],
            'image_url' => 'https://images.example/unsupported.txt',
        ],
    ]);

    Http::fake([
        'https://images.example/unsupported.txt' => Http::response('not-an-image', 200, ['Content-Type' => 'text/plain']),
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $unsupportedPath,
    ])->assertSuccessful();

    $unsupportedProduct = MasterProduct::query()->where('barcode', '9900000000071')->firstOrFail();
    expect($unsupportedProduct->getFirstMedia(MasterProduct::IMAGE_COLLECTION))->toBeNull();

    config()->set('services.openfoodfacts.image_max_bytes', 10);

    $oversizedPath = openFoodFactsJsonlPath([
        [
            'code' => '9900000000072',
            'product_name' => 'Oversized Image',
            'countries_tags' => ['en:syria'],
            'image_url' => 'https://images.example/oversized.png',
        ],
    ]);

    Http::fake([
        'https://images.example/oversized.png' => Http::response(str_repeat('a', 1024), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $oversizedPath,
    ])->assertSuccessful();

    $oversizedProduct = MasterProduct::query()->where('barcode', '9900000000072')->firstOrFail();
    expect($oversizedProduct->getFirstMedia(MasterProduct::IMAGE_COLLECTION))->toBeNull();
});

it('replaces old openfoodfacts image only after new image validates', function (): void {
    $firstPath = openFoodFactsJsonlPath([
        [
            'code' => '9900000000081',
            'product_name' => 'Reimport Image Product',
            'countries_tags' => ['en:syria'],
            'image_url' => 'https://images.example/first.png',
        ],
    ]);

    $secondPath = openFoodFactsJsonlPath([
        [
            'code' => '9900000000081',
            'product_name' => 'Reimport Image Product Updated',
            'countries_tags' => ['en:syria'],
            'image_url' => 'https://images.example/second.png',
        ],
    ]);

    $thirdPath = openFoodFactsJsonlPath([
        [
            'code' => '9900000000081',
            'product_name' => 'Reimport Image Product Failed Update',
            'countries_tags' => ['en:syria'],
            'image_url' => 'https://images.example/third.png',
        ],
    ]);

    Http::fake([
        'https://images.example/first.png' => Http::response(validImageBytes('first.png'), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $firstPath,
    ])->assertSuccessful();

    $product = MasterProduct::query()->where('barcode', '9900000000081')->firstOrFail();
    $firstMediaId = $product->getFirstMedia(MasterProduct::IMAGE_COLLECTION)?->id;
    expect($firstMediaId)->not->toBeNull();

    Http::fake([
        'https://images.example/second.png' => Http::response(validImageBytes('second.png'), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $secondPath,
    ])->assertSuccessful();

    $product->refresh();
    $secondMediaId = $product->getFirstMedia(MasterProduct::IMAGE_COLLECTION)?->id;
    expect($secondMediaId)->not->toBeNull();
    expect($secondMediaId)->not->toBe($firstMediaId);
    expect($product->getMedia(MasterProduct::IMAGE_COLLECTION)->count())->toBe(1);

    Http::fake([
        'https://images.example/third.png' => Http::response('', 500),
    ]);

    $this->artisan('supermarket:import-openfoodfacts-master-products', [
        'source' => $thirdPath,
    ])->assertSuccessful();

    $product->refresh();
    $thirdMediaId = $product->getFirstMedia(MasterProduct::IMAGE_COLLECTION)?->id;
    expect($thirdMediaId)->toBe($secondMediaId);
    expect($product->getMedia(MasterProduct::IMAGE_COLLECTION)->count())->toBe(1);
});
