<?php

declare(strict_types=1);

namespace Tests\Feature\SupermarketPromotions;

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Seeders\MasterProductSeeder;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Database\Seeders\SmCategorySeeder;
use Modules\Supermarket\Database\Seeders\SmDemoCatalogSeeder;
use Modules\Supermarket\Database\Seeders\SmOfferSeeder;
use Modules\Supermarket\Database\Seeders\SmProductMasterLinkSeeder;
use Modules\Supermarket\Database\Seeders\SmProductSeeder;
use Modules\Supermarket\Database\Seeders\SmStoreSeeder;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Tests\TestCase;

final class SupermarketDemoCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_catalog_media_promotions_and_reseeding(): void
    {
        $this->freezeTime();
        Queue::fake();
        Storage::fake((string) config('media-library.disk_name', 'public'));
        // Validate original files and catalog integrity without regenerating
        // thousands of already-covered image variants in the database test.
        config(['supermarket-promotions.enabled' => true, 'media-library.image_generators' => []]);

        $this->seed([MasterProductSeeder::class, SmStoreSeeder::class]);
        $sellerOwner = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
        $seller = SmStore::factory()->create(['owner_user_id' => $sellerOwner->id, 'slug' => 'seller-supermarket-qa', 'is_active' => true, 'is_temporarily_closed' => false, 'cover' => null, 'logo' => null]);
        $seeders = [SmCategorySeeder::class, SmProductSeeder::class, SmProductMasterLinkSeeder::class, SmOfferSeeder::class];
        $this->seed($seeders);

        $stores = SmStore::query()->orderBy('id')->get();
        self::assertCount(4, $stores);
        foreach ($stores as $store) {
            self::assertStringContainsString('/seed-media/supermarket/', $store->cover);
            self::assertStringContainsString('/seed-media/supermarket/', $store->logo);
            self::assertSame(23, $store->categories()->count());
            self::assertSame(84, $store->products()->where('barcode', 'like', 'SM-%-DEMO-%')->count());
            self::assertSame(12, $store->offers()->count());
            self::assertSame(12, $store->offers()->whereHas('offerProducts')->count());
            self::assertSame(2, $store->offers()->where('reward_type', 'specific_products')->whereHas('rewardProducts')->count());
            self::assertSame(1, $store->offers()->where('reward_type', 'cheapest_eligible')->count());
            self::assertSame(3, $store->offers()->where('reward_type', 'same_product')->count());
            self::assertSame(0, $store->products()->whereNull('master_product_id')->count());
            foreach ($store->products()->with('media')->get() as $product) {
                $media = $product->getFirstMedia(SmProduct::IMAGE_COLLECTION);
                self::assertNotNull($media, $product->name);
                self::assertSame(512, getimagesize($media->getPath())[0], $product->name);
                self::assertCount(1, $product->getMedia(SmProduct::IMAGE_COLLECTION));
            }
            foreach ($store->offers()->with(['offerProducts.product', 'rewardProducts'])->get() as $offer) {
                foreach ($offer->offerProducts as $link) {
                    self::assertEquals($store->id, $link->product->store_id);
                }
                foreach ($offer->rewardProducts as $reward) {
                    self::assertEquals($store->id, $reward->store_id);
                    self::assertFalse($offer->offerProducts->contains('product_id', $reward->id));
                }
            }
        }

        $store = $stores->firstWhere('slug', 'supermarket-al-atrash');
        $product = fn (string $key) => $store->products()->where('barcode', 'SM-'.$store->id.'-DEMO-'.$key)->firstOrFail();
        self::assertSame(35, $product('rice')->effectivePrice());
        self::assertSame(175, $product('laundry-new')->effectivePrice());
        self::assertSame(0, $product('wafer')->effectivePrice());
        self::assertSame(10, $product('water-new')->effectivePrice());
        self::assertSame(0, $product('out-of-stock')->stock_quantity);
        self::assertFalse($product('unavailable')->is_available);
        self::assertTrue($product('expired')->expires_at->isPast());
        self::assertNotEquals($product('rice')->master_product_id, $product('rice-family')->master_product_id);
        self::assertNotNull($product('rice')->masterProduct->category_id);
        self::assertNotNull($product('rice')->masterProduct->getFirstMedia('master-product-image'));
        self::assertEquals(3000, $product('water-pack')->masterProduct->package_quantity);
        self::assertEquals('milliliter', $product('water-pack')->masterProduct->package_unit);
        self::assertSame(25, $product('orange-juice')->effectivePrice());
        self::assertSame(50, $product('apple-juice')->effectivePrice());
        self::assertEquals($product('rice')->master_product_id, $seller->products()->where('barcode', 'SM-'.$seller->id.'-DEMO-rice')->firstOrFail()->master_product_id);

        $active = $store->offers()->where('reward_type', 'specific_products')->firstOrFail();
        $this->withHeader('X-Supermarket-Promotions', '1')
            ->getJson('/api/v1/user/supermarket/offers/'.$active->id)
            ->assertOk()
            ->assertJsonPath('data.promotionType', 'buy_x_get_y')
            ->assertJsonPath('data.rewardType', 'specific_products')
            ->assertJsonStructure(['data' => ['imageUrl', 'offerProducts' => [['product' => ['imageUrl']]], 'rewardProducts' => [['imageUrl']]]]);
        $this->withHeader('X-Supermarket-Promotions', '0')
            ->getJson('/api/v1/user/supermarket/offers/'.$active->id)->assertNotFound();
        $scheduled = $store->offers()->where('show_upcoming', true)->where('promotion_type', 'buy_x_get_y')->firstOrFail();
        $this->withHeader('X-Supermarket-Promotions', '1')
            ->getJson('/api/v1/user/supermarket/offers/'.$scheduled->id)->assertNotFound();
        $this->getJson('/api/v1/user/supermarket/offers/'.$scheduled->id.'?includeUpcoming=1')
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->getJson('/api/v1/user/supermarket/products/'.$product('milk-new')->id.'/compare')
            ->assertOk()->assertJsonCount(3, 'data');
        Sanctum::actingAs($sellerOwner);
        $this->getJson('/api/v1/sm-offers?perPage=30')
            ->assertOk()->assertJsonCount(12, 'data');

        $mediaId = $product('rice')->getFirstMedia(SmProduct::IMAGE_COLLECTION)->id;
        $before = [$store->products()->count(), $store->categories()->count(), $store->offers()->count()];
        $version = $active->rule_version;
        $unrelated = SmProduct::factory()->create(['master_product_id' => null]);
        $legacy = $store->products()->where('barcode', 'SM-'.$store->id.'-0001')->firstOrFail();
        $legacy->getFirstMedia(SmProduct::IMAGE_COLLECTION)->delete();
        $legacy->unsetRelation('media');
        SeederMedia::ensureSingleMedia($legacy, SmProduct::IMAGE_COLLECTION, '', 'sm-product-'.$legacy->id);
        self::assertSame(64, getimagesize($legacy->fresh()->getFirstMedia(SmProduct::IMAGE_COLLECTION)->getPath())[0]);

        $this->seed(SmDemoCatalogSeeder::class);
        self::assertSame($before, [$store->products()->count(), $store->categories()->count(), $store->offers()->count()]);
        self::assertSame($mediaId, $product('rice')->getFirstMedia(SmProduct::IMAGE_COLLECTION)->id);
        self::assertEquals($version, $active->fresh()->rule_version);
        self::assertNull($unrelated->fresh()->master_product_id);
        self::assertSame(512, getimagesize($legacy->fresh()->getFirstMedia(SmProduct::IMAGE_COLLECTION)->getPath())[0]);
    }
}
