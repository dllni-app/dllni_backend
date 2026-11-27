<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use App\Models\MasterProductCategory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Supermarket\Models\SmProduct;
use Spatie\MediaLibrary\HasMedia;

final class SmDemoCatalog
{
    private const CATEGORY_ALIASES = [
        'essentials' => 'syrian-essentials',
        'dairy' => 'syrian-dairy',
        'drinks' => 'syrian-drinks',
        'snacks' => 'syrian-snacks',
        'cleaning' => 'syrian-cleaning',
    ];

    private static ?array $catalog = null;

    public static function load(): array
    {
        if (app()->environment('production')) {
            throw new LogicException('Supermarket demo catalog is not for production.');
        }
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $data = json_decode(file_get_contents(__DIR__.'/supermarket-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($data['schemaVersion'] !== 1) {
            throw new LogicException('Unsupported supermarket catalog schema.');
        }
        $keys = [];
        $names = [];
        foreach ($data['products'] as $row) {
            if (isset($keys[$row['key']]) || isset($names[$row['name']])
                || ! isset($data['categories'][$row['category']])
                || $row['price'] <= 0 || $row['stock_quantity'] < 0
                || (isset($row['discounted_price']) && ($row['discounted_price'] < 0 || $row['discounted_price'] >= $row['price']))) {
                throw new LogicException('Invalid demo product: '.$row['key']);
            }
            $keys[$row['key']] = $names[$row['name']] = true;
            self::asset($row['key']);
            foreach ($row['store_prices'] ?? [] as $price) {
                if ($price <= 0 || (isset($row['discounted_price']) && $row['discounted_price'] >= $price)) {
                    throw new LogicException('Invalid store price: '.$row['key']);
                }
            }
        }
        foreach ($data['legacyArtwork'] as $name => $row) {
            if (isset($keys[$row['key']]) || isset($names[$name])) {
                throw new LogicException('Duplicate demo artwork: '.$row['key']);
            }
            $keys[$row['key']] = true;
            self::asset($row['key']);
        }
        foreach ($data['categories'] as $row) {
            self::asset($row['image']);
        }
        foreach (['atrash', 'sultan', 'noor', 'seller'] as $store) {
            self::asset('store-'.$store.'-cover');
            self::asset('store-'.$store.'-logo');
        }
        return self::$catalog = $data;
    }

    public static function categorySlug(string $store, string $slug): string
    {
        return str_starts_with($store, 'seller-supermarket-') ? (self::CATEGORY_ALIASES[$slug] ?? $slug) : $slug;
    }

    public static function categoryImage(string $slug): string
    {
        $canonical = array_search($slug, self::CATEGORY_ALIASES, true) ?: $slug;
        return self::url(self::load()['categories'][$canonical]['image']);
    }

    public static function storeImage(string $slug, string $kind): string
    {
        $key = match ($slug) {
            'supermarket-al-atrash' => 'atrash',
            'supermarket-al-sultan' => 'sultan',
            'supermarket-al-noor' => 'noor',
            default => 'seller',
        };
        return self::url('store-'.$key.'-'.$kind);
    }

    public static function asset(string $key): string
    {
        if (! preg_match('/^[a-z0-9-]+$/D', $key)) {
            throw new LogicException('Invalid seed image key.');
        }
        $path = public_path('seed-media/supermarket/'.$key.'.webp');
        $size = is_file($path) ? getimagesize($path) : false;
        if ($size === false || $size[0] < 400 || $size[1] < 400 || $size[2] !== IMAGETYPE_WEBP) {
            throw new LogicException('Missing or invalid supermarket image: '.$path);
        }
        return $path;
    }

    public static function url(string $key): string
    {
        self::asset($key);
        return asset('seed-media/supermarket/'.$key.'.webp');
    }

    public static function artworkForName(string $name): string
    {
        $data = self::load();
        if (isset($data['legacyArtwork'][$name])) {
            return $data['legacyArtwork'][$name]['key'];
        }
        foreach ($data['products'] as $row) {
            if ($row['name'] === $name) {
                return $row['key'];
            }
        }
        throw new LogicException('No artwork for seeded supermarket product: '.$name);
    }

    public static function master(array $row): MasterProduct
    {
        $size = $row['size'];
        $unit = MasterProductUnit::Pack;
        $quantity = 1;
        if (preg_match('/^(\d+(?:\.\d+)?) (kg|g|L|ml|pcs)$/D', $size, $m)) {
            $quantity = (float) $m[1];
            $unit = match ($m[2]) {
                'kg' => MasterProductUnit::Kilogram,
                'g' => MasterProductUnit::Gram,
                'L' => MasterProductUnit::Liter,
                'ml' => MasterProductUnit::Milliliter,
                'pcs' => MasterProductUnit::Piece,
            };
        } elseif (preg_match('/^(\d+) x (\d+(?:\.\d+)?) (ml|L)$/D', $size, $m)) {
            $quantity = (float) $m[1] * (float) $m[2];
            $unit = $m[3] === 'ml' ? MasterProductUnit::Milliliter : MasterProductUnit::Liter;
        }
        $categoryData = self::load()['categories'][$row['category']];
        $category = MasterProductCategory::query()->firstOrCreate(
            ['slug' => $row['category']],
            ['name' => $categoryData['name'], 'is_active' => true]
        );
        // Exact pack identity prevents comparison of e.g. 1 kg and 5 kg as the same SKU.
        $master = MasterProduct::query()->firstOrCreate(['name' => $row['name']], [
            'unit' => $unit->value,
            'category_id' => $category->id,
            'package_quantity' => $quantity,
            'package_unit' => $unit->value,
            'sell_mode' => 'package',
            'description' => $row['name'],
            'is_active' => true,
        ]);
        self::attach($master, MasterProduct::IMAGE_COLLECTION, $row['key'], 'master-product-'.$master->id.'.png');
        return $master;
    }

    public static function attachProduct(SmProduct $product): void
    {
        self::attach($product, SmProduct::IMAGE_COLLECTION, self::artworkForName($product->name), 'sm-product-'.$product->id.'.png');
    }

    private static function attach(Model&HasMedia $model, string $collection, string $key, string $oldFile): void
    {
        $path = self::asset($key);
        $hash = hash_file('sha256', $path);
        $media = $model->getMedia($collection);
        // Never remove real merchant uploads; only replace this seeder's own files.
        $owned = $media->filter(fn ($item) => $item->hasCustomProperty('sm_demo_asset') || $item->file_name === $oldFile);
        if ($media->count() > $owned->count()) {
            return;
        }
        $matching = $owned->first(fn ($item) => $item->getCustomProperty('sm_demo_hash') === $hash
            && is_file($item->getPath()) && hash_file('sha256', $item->getPath()) === $hash);
        if ($matching === null) {
            $matching = $model->addMedia($path)->preservingOriginal()
                ->usingFileName('sm-demo-'.$key.'.webp')
                ->withCustomProperties(['sm_demo_asset' => $key, 'sm_demo_hash' => $hash])
                ->toMediaCollection($collection);
        }
        foreach ($owned as $item) {
            if ($item->id !== $matching->id) {
                $item->delete();
            }
        }
        $model->unsetRelation('media');
    }
}
