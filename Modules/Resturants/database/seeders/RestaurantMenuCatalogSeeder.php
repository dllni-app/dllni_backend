<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\ModifierGroup;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Services\ProductClassificationRules;
use Spatie\MediaLibrary\HasMedia;

/**
 * Rebuild the known demo restaurants' menus without deleting order history,
 * changing merchant accounts, or depending on an external image server.
 */
final class RestaurantMenuCatalogSeeder extends Seeder
{
    private const SELLER_EMAIL = 'seller@restaurant.dllni.sy';

    private const SELLER_MENU = 'seller-restaurant-4b227777';

    private const RETIRED = 'صنف تجريبي قديم خارج القائمة الحالية.';

    /** @var array<string, string> */
    private const FOOD_ARTWORK = [
        'shawarma' => 'shawarma', 'burger' => 'burger', 'pizza' => 'margherita',
        'grill' => 'kebab', 'fried-chicken' => 'karaage', 'breakfast' => 'fatteh',
        'pasta' => 'carbonara', 'asian' => 'sushi', 'sides' => 'hummus',
        'desserts' => 'knafeh', 'drinks' => 'juice',
        'falafel' => 'falafel', 'potato' => 'fries', 'salads' => 'salad', 'pastries' => 'feteer',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('The restaurant demo menu seeder is not for production.');
        }

        $catalog = json_decode(
            file_get_contents(__DIR__.'/restaurant-menu-catalog.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        // Preflight before touching the DB; never silently replace a photo with a 1px placeholder.
        foreach ($catalog['images'] as $key => $url) {
            $this->asset($key);
        }
        $this->validateCatalog($catalog);

        $this->ensureDemoRestaurants($catalog);
        $this->call(FoodDiscoveryCategorySeeder::class);
        $foodCategories = FoodDiscoveryCategory::query()->get()->keyBy('slug');

        foreach (self::FOOD_ARTWORK as $slug => $image) {
            $this->attachImage($foodCategories->get($slug), 'category-image', $image);
        }

        foreach ($catalog['menus'] as $slug => $sections) {
            // This seller's slug contains a hash of the user ID, which can change on a fresh seed.
            $restaurant = $slug === self::SELLER_MENU
                ? Restaurant::query()->where('email', self::SELLER_EMAIL)->first()
                : Restaurant::query()->where('slug', $slug)->first();

            if ($restaurant === null) {
                throw new LogicException('Missing demo restaurant: '.$slug);
            }

            // The known demo seller serves primarily Levantine food, not Italian cuisine.
            if ($slug === self::SELLER_MENU) {
                $cuisineId = DB::table('cuisine_types')->where('slug', 'mediterranean')->value('id');
                if ($cuisineId !== null) {
                    $restaurant->cuisineTypes()->sync([$cuisineId]);
                }
            }
            $this->seedRestaurant($restaurant, $sections, $catalog, $foodCategories);
        }
    }

    private function ensureDemoRestaurants(array $catalog): void
    {
        if (empty($catalog['demo_restaurants'])) {
            return;
        }

        $owner = User::query()->where('email', 'restaurant.owner@dllni.sy')->firstOrFail();
        $cuisineId = DB::table('cuisine_types')->where('slug', 'mediterranean')->value('id');

        foreach ($catalog['demo_restaurants'] as $slug => $attributes) {
            $restaurant = Restaurant::firstOrCreate(['slug' => $slug], [
                ...$attributes,
                'user_id' => $owner->id,
                'is_active' => true,
                'is_temporarily_closed' => false,
                'is_featured' => false,
                'visibility_score' => 100,
                'reputation_score' => 100,
            ]);
            if ($cuisineId !== null) {
                $restaurant->cuisineTypes()->syncWithoutDetaching([$cuisineId]);
            }
            foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
                DB::table('operating_hours')->updateOrInsert(
                    ['restaurant_id' => $restaurant->id, 'day_of_week' => $day],
                    ['open_time' => '11:00', 'close_time' => '23:00', 'is_closed' => false, 'updated_at' => now()],
                );
            }
        }
    }

    private function seedRestaurant(Restaurant $restaurant, array $sections, array $catalog, $foodCategories): void
    {
        $groupIds = [];
        $activeProductIds = [];
        $menuProductIds = [];
        $categoryImages = [];
        $hero = null;
        $secondaryHero = null;

        $sectionIds = [];
        foreach ($sections as $sectionKey => $section) {
            $sectionName = $section['name'];
            $items = $section['products'];
            $category = Category::query()->where('restaurant_id', $restaurant->id)
                ->where('slug', $sectionKey)->first()
                ?? Category::query()->where('restaurant_id', $restaurant->id)
                    ->where('name', $sectionName)->first()
                ?? new Category(['restaurant_id' => $restaurant->id]);

            $category->fill([
                'name' => $sectionName,
                'slug' => $sectionKey,
                'sort_order' => $section['sortOrder'],
                'parent_id' => $section['parentKey'] === null ? null : $sectionIds[$section['parentKey']],
                'is_active' => true,
            ])->save();
            $sectionIds[$sectionKey] = $category->id;

            if ($items === []) {
                continue;
            }

            $categoryImages[$category->id] = $items[0]['imageKey'];
            $this->attachImage($category, 'category-image', $items[0]['imageKey']);
            $hero ??= $items[0]['imageKey'];
            if ($secondaryHero === null && $hero !== $items[0]['imageKey']) {
                $secondaryHero = $items[0]['imageKey'];
            }

            $sectionProducts = [];
            foreach ($items as $index => $row) {
                $name = $row['name'];
                $description = $row['description'];
                $price = $row['price'];
                $image = $row['imageKey'];
                $itemType = $row['itemType'];
                $groupKeys = $row['modifierGroupKeys'];
                $discount = $row['discount'];
                $kind = $row['productKind'];
                $foodSlug = $row['foodSlug'];
                $foodCategory = $foodCategories->get($foodSlug);
                if ($foodCategory === null) {
                    throw new LogicException('Unknown food category: '.$foodSlug);
                }
                $classification = [
                    'item_type' => $itemType,
                    'product_kind' => $kind,
                    'catalog_role' => $row['catalogRole'],
                    'primary_food_category_id' => $foodCategory->id,
                    'servings' => $row['servings'],
                    'included_components' => $row['includedComponents'],
                ];
                ProductClassificationRules::validate($classification, true);

                // Match the demo product by restaurant/name, retaining the original product ID.
                $matches = Product::query()->where('restaurant_id', $restaurant->id)
                    ->where('name', $name)->orderBy('id')->get();
                $product = $matches->first() ?? new Product([
                    'restaurant_id' => $restaurant->id,
                    'name' => $name,
                ]);

                $product->fill([
                    'category_id' => $category->id,
                    'description' => $description,
                    ...$classification,
                    'classification_status' => 'reviewed',
                    'classified_at' => $product->classified_at ?? now(),
                    'search_tags' => array_values(array_unique([$name, $sectionName, $foodCategory->name_ar, $foodCategory->name_en])),
                    'price' => $price,
                    'discounted_price' => $discount,
                    'is_available' => true,
                    'unavailable_until' => null,
                    'availability_note' => null,
                    'stock_quantity' => 24 + ($index % 5) * 12,
                    'low_stock_threshold' => 5,
                    'preparation_time' => match ($kind) {
                        'beverage' => 5, 'dessert' => 8, 'side_dish' => 12,
                        'combo_meal' => 25, default => 18 + ($index % 4) * 3,
                    },
                    'is_featured' => $kind === 'main_dish' || $kind === 'combo_meal'
                        ? $index < 2 || $discount !== null : false,
                ])->save();

                $ids = [];
                foreach ($groupKeys as $key) {
                    $groupIds[$key] ??= $this->seedGroup($restaurant, $catalog['groups'][$key]);
                    $ids[] = $groupIds[$key];
                }
                // Do not keep the old generic add-on groups on the curated products.
                $product->modifierGroups()->sync($ids);

                $this->attachProductPhotos($product, $image);
                $activeProductIds[] = $product->id;
                $menuProductIds[] = $product->id;
                $sectionProducts[] = $product;

                // Any duplicate created by an older seed remains addressable for historical orders.
                foreach ($matches->skip(1) as $duplicate) {
                    $this->retire($duplicate);
                    $this->attachProductPhotos($duplicate, $image);
                    $menuProductIds[] = $duplicate->id;
                }
            }

            // Real substitutes belong to the same menu section and product kind, not arbitrary adjacent IDs.
            $this->seedSectionSubstitutions($restaurant, $sectionProducts);
        }

        // The old menu repeated a generic list in all five demo restaurants. Hide only known
        // legacy seed items not in this merchant's curated menu; never delete order line references.
        $legacyProducts = Product::query()->where('restaurant_id', $restaurant->id)
            ->whereIn('name', array_keys($catalog['legacy']))
            ->whereNotIn('id', $activeProductIds)->get();

        foreach ($legacyProducts as $product) {
            [$image, $kind, $foodSlug, $itemType] = $catalog['legacy'][$product->name];
            $product->fill([
                'item_type' => $itemType,
                'catalog_role' => 'independent',
                'product_kind' => $kind,
                'classification_status' => 'reviewed',
                'classified_at' => $product->classified_at ?? now(),
                'primary_food_category_id' => $foodCategories->get($foodSlug)->id,
            ])->save();
            $this->retire($product);
            $this->attachProductPhotos($product, $image);
            $menuProductIds[] = $product->id;
            $categoryImages[$product->category_id] ??= $image;
        }

        // Replace generic placeholder artwork even for legacy categories retained for history.
        foreach ($restaurant->categories()->get() as $category) {
            if (isset($categoryImages[$category->id])) {
                $this->attachImage($category, 'category-image', $categoryImages[$category->id]);
            }
        }

        // Remove only obsolete seed-to-seed suggestions, never other merchants' substitutions.
        DB::table('restaurant_product_substitutions')
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('product_id', $menuProductIds)
            ->whereIn('substitute_product_id', $menuProductIds)
            ->get()->each(function ($link): void {
                $source = Product::find($link->product_id);
                $target = Product::find($link->substitute_product_id);
                if ($source === null || $target === null || !$source->is_available || !$target->is_available
                     || $source->primary_food_category_id !== $target->primary_food_category_id || $source->product_kind !== $target->product_kind) {
                    DB::table('restaurant_product_substitutions')
                        ->where('restaurant_id', $link->restaurant_id)
                        ->where('product_id', $link->product_id)
                        ->where('substitute_product_id', $link->substitute_product_id)->delete();
                }
            });

        $this->call(RestaurantDemoOfferSeeder::class, false, ['restaurantId' => $restaurant->id]);

        if ($hero !== null) {
            $this->attachImage($restaurant, 'primary-image', $hero);
            $this->attachImage($restaurant, 'banner-image', $secondaryHero ?? $hero);
        }
    }

    private function seedGroup(Restaurant $restaurant, array $data): int
    {
        [$name, $minimum, $maximum, $options] = $data;
        if ($minimum < 0 || $maximum < $minimum || $maximum > count($options)) {
            throw new LogicException('Invalid demo modifier group: '.$name);
        }
        $group = ModifierGroup::query()->updateOrCreate([
            'restaurant_id' => $restaurant->id,
            'name' => $name,
        ], [
            'is_required' => $minimum > 0,
            'min_selections' => $minimum,
            'max_selections' => $maximum,
        ]);

        foreach ($options as $index => [$label, $price]) {
            Modifier::query()->updateOrCreate([
                'modifier_group_id' => $group->id,
                'name' => $label,
            ], [
                'price' => $price,
                'sort_order' => $index + 1,
            ]);
        }

        return $group->id;
    }

    private function retire(Product $product): void
    {
        $product->fill([
            'is_available' => false,
            'unavailable_until' => null,
            'availability_note' => self::RETIRED,
            'is_featured' => false,
        ])->save();
        $product->modifierGroups()->detach();
    }

    private function seedSectionSubstitutions(Restaurant $restaurant, array $products): void
    {
        foreach ($products as $source) {
            foreach ($products as $target) {
                if ($source->id === $target->id || $source->product_kind !== $target->product_kind
                     || $source->item_type !== $target->item_type
                     || $source->primary_food_category_id !== $target->primary_food_category_id) {
                    continue;
                }
                DB::table('restaurant_product_substitutions')->updateOrInsert([
                    'restaurant_id' => $restaurant->id,
                    'product_id' => $source->id,
                    'substitute_product_id' => $target->id,
                ], ['updated_at' => now(), 'created_at' => now()]);
            }
        }
    }

    private function validateCatalog(array $catalog): void
    {
        if (($catalog['schemaVersion'] ?? null) !== 2) {
            throw new LogicException('Unsupported restaurant fixture schema.');
        }
        foreach ($catalog['menus'] as $merchant => $sections) {
            $roots = [];
            $keys = [];
            $names = [];
            foreach ($sections as $sectionKey => $section) {
                if ($section['parentKey'] === null) {
                    $roots[$sectionKey] = true;
                } elseif (!isset($roots[$section['parentKey']])) {
                    throw new LogicException("Invalid or unordered section parent: {$merchant}/{$sectionKey}");
                }
                if (trim($section['name']) === '' || !is_int($section['sortOrder']) || $section['sortOrder'] < 0) {
                    throw new LogicException("Invalid section: {$merchant}/{$sectionKey}");
                }
                foreach ($section['products'] as $row) {
                    $key = $row['key'];
                    $name = $row['name'];
                    if (isset($keys[$key]) || isset($names[$name]) || trim($name) === '') {
                        throw new LogicException("Duplicate or empty product identity: {$merchant}/{$key}");
                    }
                    $keys[$key] = $names[$name] = true;
                    if (!isset(self::FOOD_ARTWORK[$row['foodSlug']], $catalog['images'][$row['imageKey']])
                        || !is_numeric($row['price']) || $row['price'] < 0
                        || ($row['discount'] !== null && (!is_numeric($row['discount']) || $row['discount'] < 0 || $row['discount'] > $row['price']))) {
                        throw new LogicException("Invalid family, image or price: {$merchant}/{$key}");
                    }
                    foreach ($row['modifierGroupKeys'] as $groupKey) {
                        if (!isset($catalog['groups'][$groupKey])) {
                            throw new LogicException("Unknown modifier group: {$merchant}/{$key}/{$groupKey}");
                        }
                    }
                }
            }
        }
    }

    private function asset(string $key): string
    {
        $path = __DIR__.'/restaurant-menu-images/'.$key.'.jpg';
        $size = is_file($path) ? @getimagesize($path) : false;
        if ($size === false || $size[0] < 300 || $size[1] < 200 || $size[2] !== IMAGETYPE_JPEG) {
            throw new LogicException('Missing or invalid demo food photo: '.$path);
        }

        return $path;
    }

    private function attachProductPhotos(Product $product, string $key): void
    {
        $this->attachImage($product, 'primary-image', $key);
        // The gallery must never show legacy 1px placeholders. The same actual photo is
        // intentionally used in the gallery until independent alternate angles are available.
        $this->attachImage($product, 'images', $key, true);
    }

    private function attachImage(HasMedia $model, string $collection, string $key, bool $replaceSeedGallery = false): void
    {
        $path = $this->asset($key);
        $hash = hash_file('sha256', $path);
        $media = $model->getMedia($collection);
        $matching = $media->first(fn ($item) => $item->getCustomProperty('menu_seed_hash') === $hash
            && is_file($item->getPath()));

        if ($matching === null) {
            $matching = $model->addMedia($path)->preservingOriginal()
                ->usingName('Restaurant menu: '.$key)
                ->usingFileName('menu-'.$key.'.jpg')
                ->withCustomProperties(['menu_seed_hash' => $hash, 'menu_seed_asset' => $key])
                ->toMediaCollection($collection);
        }

        if ($replaceSeedGallery) {
            foreach ($media as $item) {
                if ($item->id === $matching->id) {
                    continue;
                }
                // Preserve actual uploaded images; replace the old synthetic 1px and prior seed assets.
                $size = is_file($item->getPath()) ? @getimagesize($item->getPath()) : false;
                if ($item->hasCustomProperty('menu_seed_hash') || $size === false || $size[0] < 100 || $size[1] < 100) {
                    $item->delete();
                }
            }
        }
        $model->unsetRelation('media');
    }
}
