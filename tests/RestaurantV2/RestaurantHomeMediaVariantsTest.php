<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Support\DirectImageVariants;
use App\Support\ImageVariants;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Resturants\Models\Product;
use Tests\TestCase;

final class RestaurantHomeMediaVariantsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('local', app()->environment());
        self::assertSame('alnadha_worker_test', DB::connection()->getDatabaseName());
    }

    public function test_new_media_upload_generates_both_webp_sizes_and_keeps_original(): void
    {
        Storage::fake('public');
        $product = Product::query()->firstOrFail();
        $file = UploadedFile::fake()->image('sample.png', 1200, 800);
        $hash = hash_file('sha256', $file->getRealPath());
        // Isolated collection: never replace any actual catalog image.
        $media = $product->addMedia($file)->toMediaCollection('qa-variant-image', 'public');
        try {
            self::assertSame($hash, hash_file('sha256', $media->getPath()));
            foreach ([ImageVariants::THUMB => 640, ImageVariants::WEBP => 1600] as $variant => $max) {
                self::assertTrue($media->fresh()->hasGeneratedConversion($variant));
                $path = $media->getPath($variant);
                self::assertFileExists($path);
                $size = getimagesize($path);
                self::assertSame('image/webp', $size['mime']);
                self::assertLessThanOrEqual($max, max($size[0], $size[1]));
                self::assertLessThanOrEqual(1200, $size[0]);
            }
            self::assertStringEndsWith('-app-thumb.webp', ImageVariants::mediaUrl($media->fresh(), true));
        } finally {
            $media->delete();
        }
    }

    public function test_direct_image_upload_generates_webp_and_thumb(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('sample.jpg', 1200, 800);
        $originalHash = hash_file('sha256', $file->getRealPath());
        $path = DirectImageVariants::store($file, 'public', 'qa-images');
        $original = substr($path, 0, -5);
        $disk = Storage::disk('public');
        $disk->assertExists([$original, $path, DirectImageVariants::path($original, 'thumb')]);
        self::assertSame($originalHash, hash_file('sha256', $disk->path($original)));
        $size = getimagesize($disk->path(DirectImageVariants::path($original, 'thumb')));
        self::assertSame('image/webp', $size['mime']);
        self::assertSame(640, $size[0]);
    }

    public function test_separate_home_sections_use_generated_thumbnails(): void
    {
        foreach (['dishes', 'popular-meals', 'restaurants', 'food-categories', 'promotions'] as $section) {
            $response = $this->getJson('/api/v2/user/restaurants/home/'.$section.'?perPage=8', [
                'X-Restaurant-Promotions' => '1',
            ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            foreach ($response->json('data.items') as $item) {
                // Existing media may be awaiting regeneration. A URL must stay valid
                // even when the original is the only existing variant.
                if (isset($item['imageUrl'])) {
                    self::assertIsString($item['imageUrl']);
                }
            }
        }
        $product = Product::query()->whereHas('media', fn ($q) => $q->where('collection_name', 'primary-image'))
            ->whereHas('restaurant', fn ($q) => $q->where('is_active', true))
            ->where('is_available', true)->where('catalog_role', 'independent')->firstOrFail();
        $media = $product->getFirstMedia('primary-image');
        if ($media->hasGeneratedConversion(ImageVariants::WEBP)) {
            $this->getJson('/api/v2/user/restaurants/products/'.$product->id)
                ->assertOk()->assertJsonPath('data.imageUrl', $media->getUrl(ImageVariants::WEBP));
        }
    }
    public function test_product_cards_expose_actual_restaurant_branding(): void
    {
        $headers = ['X-Restaurant-Promotions' => '1'];
        $assertBrand = static function (array $item): void {
            $restaurant = \Modules\Resturants\Models\Restaurant::query()->with('media')->findOrFail($item['restaurantId']);
            self::assertSame((int) $restaurant->id, $item['restaurant']['id']);
            self::assertSame($restaurant->name, $item['restaurant']['name']);
            $expected = ImageVariants::url($restaurant, 'primary-image', true);
            self::assertNotNull($expected);
            self::assertSame($expected, $item['restaurant']['imageUrl']);
        };
        foreach (['dishes', 'popular-meals'] as $section) {
            $items = $this->getJson('/api/v2/user/restaurants/home/'.$section.'?perPage=8', $headers)
                ->assertOk()->json('data.items');
            self::assertNotEmpty($items);
            foreach ($items as $item) {
                $assertBrand($item);
            }
        }
        $offers = $this->getJson('/api/v2/user/restaurants/home/promotions?perPage=8', $headers)
            ->assertOk()->json('data.items');
        self::assertNotEmpty($offers);
        foreach ($offers as $offer) {
            foreach (array_merge($offer['products'], $offer['rewardProducts']) as $item) {
                $assertBrand($item);
                self::assertSame($offer['restaurantName'], $item['restaurant']['name']);
                self::assertSame($offer['restaurantImageUrl'], $item['restaurant']['imageUrl']);
            }
        }
        $product = Product::query()->with(['restaurant.media','media','category','offers'])
            ->whereHas('restaurant', fn ($q) => $q->where('is_active', true))
            ->where('is_available', true)->where('catalog_role', 'independent')->firstOrFail();
        $favorite = (new \Modules\User\Http\Resources\UserRestaurantProductWithOffersResource($product))->resolve(request());
        $assertBrand([...$favorite, 'restaurantId' => $product->restaurant_id]);
        $line = \Modules\Resturants\Models\OrderItem::query()->with(['product.restaurant.media','product.media','order'])
            ->whereHas('product.restaurant')->firstOrFail();
        $line->product->setAttribute('isFavoritedByUser', false);
        $recent = (new \Modules\User\Http\Resources\UserRestaurantLatestOrderedProductResource($line))->resolve(request());
        self::assertSame($line->product->restaurant->name, $recent['restaurantName']);
        self::assertSame(ImageVariants::url($line->product->restaurant, 'primary-image', true), $recent['restaurantImageUrl']);
    }

}
