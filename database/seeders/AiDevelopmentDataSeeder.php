<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MasterProductUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AiDevelopmentDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection()->disableQueryLog();

        $masterProductIds = $this->seedMasterProducts((int) env('AI_DEV_MASTER_PRODUCTS', 600));
        $this->seedRecipes((int) env('AI_DEV_RECIPES', 240), $masterProductIds);
        $this->seedRestaurantProducts((int) env('AI_DEV_RESTAURANT_PRODUCTS_PER_CATEGORY', 80), $masterProductIds);
        $this->seedRestaurantOffers((int) env('AI_DEV_RESTAURANT_OFFERS_PER_RESTAURANT', 24));
        $this->seedSupermarketProducts((int) env('AI_DEV_SUPERMARKET_PRODUCTS_PER_CATEGORY', 120), $masterProductIds);
        $this->seedSupermarketOffers((int) env('AI_DEV_SUPERMARKET_OFFERS_PER_STORE', 28));
    }

    /**
     * @return Collection<int, int>
     */
    private function seedMasterProducts(int $count): Collection
    {
        $templates = [
            ['name' => 'حليب طويل الأجل', 'unit' => MasterProductUnit::Liter, 'brand' => 'المراعي', 'aliases' => ['حليب', 'حليب سائل']],
            ['name' => 'لبن زبادي', 'unit' => MasterProductUnit::Gram, 'brand' => 'نادك', 'aliases' => ['زبادي', 'لبن']],
            ['name' => 'رز بسمتي', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'أبو كاس', 'aliases' => ['رز', 'بسمتي']],
            ['name' => 'سكر أبيض', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'الأسرة', 'aliases' => ['سكر', 'سكر ناعم']],
            ['name' => 'زيت دوار الشمس', 'unit' => MasterProductUnit::Liter, 'brand' => 'العافية', 'aliases' => ['زيت', 'زيت نباتي']],
            ['name' => 'زيت زيتون بكر ممتاز', 'unit' => MasterProductUnit::Liter, 'brand' => 'الجوف', 'aliases' => ['زيت زيتون', 'زيت بكر']],
            ['name' => 'دجاج كامل طازج', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'الوطنية', 'aliases' => ['دجاج', 'فروج']],
            ['name' => 'صدور دجاج', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'رضوى', 'aliases' => ['صدور', 'دجاج فيليه']],
            ['name' => 'لحم بقري مفروم', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'المزرعة', 'aliases' => ['لحم مفروم', 'لحم بقري']],
            ['name' => 'طماطم طازجة', 'unit' => MasterProductUnit::Kilogram, 'brand' => 'محلي', 'aliases' => ['طماطم', 'بندورة']],
        ];
        $packSizes = ['250غ', '500غ', '750غ', '1كغ', '2كغ', '1لتر', '2لتر', '6حبات', '12حبة'];

        DB::table('master_products')->where('barcode', 'like', '880%')->delete();

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $template = $templates[($i - 1) % count($templates)];
            $size = $packSizes[array_rand($packSizes)];
            $rows[] = [
                'name' => sprintf('%s %s', $template['name'], $size),
                'barcode' => sprintf('880%010d', $i),
                'unit' => $template['unit']->value,
                'brand' => $template['brand'],
                'description' => sprintf('منتج واقعي لبيانات التطوير: %s من علامة %s.', $template['name'], $template['brand']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('master_products')->insert($chunk);
        }

        $products = DB::table('master_products')
            ->where('barcode', 'like', '880%')
            ->select('id', 'barcode')
            ->orderBy('id')
            ->get();

        $masterIds = $products->pluck('id');
        DB::table('master_product_aliases')->whereIn('master_product_id', $masterIds)->delete();

        $aliasRows = [];
        foreach ($products as $index => $product) {
            $template = $templates[$index % count($templates)];
            $size = $packSizes[array_rand($packSizes)];
            foreach ($template['aliases'] as $alias) {
                $aliasRows[] = [
                    'master_product_id' => $product->id,
                    'alias' => $alias,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $aliasRows[] = [
                'master_product_id' => $product->id,
                'alias' => sprintf('%s %s', $template['aliases'][0], $size),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($aliasRows, 2000) as $chunk) {
            DB::table('master_product_aliases')->insert($chunk);
        }

        return $masterIds->values();
    }

    /**
     * @param  Collection<int, int>  $masterProductIds
     */
    private function seedRecipes(int $count, Collection $masterProductIds): void
    {
        if ($masterProductIds->isEmpty()) {
            return;
        }

        $recipeNames = ['كبسة دجاج', 'مقلوبة باذنجان', 'بيتزا خضار', 'شوربة عدس', 'فتوش', 'تبولة', 'مكرونة بالصلصة'];
        $units = array_map(static fn (MasterProductUnit $unit): string => $unit->value, MasterProductUnit::cases());

        DB::table('recipes')->where('slug', 'like', 'ai-real-recipe-%')->delete();

        $recipeRows = [];
        for ($i = 1; $i <= $count; $i++) {
            $baseName = $recipeNames[($i - 1) % count($recipeNames)];
            $recipeRows[] = [
                'name' => sprintf('%s رقم %d', $baseName, $i),
                'slug' => sprintf('ai-real-recipe-%04d', $i),
                'description' => sprintf('وصفة عربية واقعية للتجارب الذكية (%d).', $i),
                'servings' => random_int(2, 8),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($recipeRows, 1000) as $chunk) {
            DB::table('recipes')->insert($chunk);
        }

        $recipeIds = DB::table('recipes')
            ->where('slug', 'like', 'ai-real-recipe-%')
            ->pluck('id')
            ->values();

        $ingredientRows = [];
        foreach ($recipeIds as $recipeId) {
            for ($j = 1; $j <= 5; $j++) {
                $ingredientRows[] = [
                    'recipe_id' => $recipeId,
                    'master_product_id' => $masterProductIds->random(),
                    'quantity' => random_int(1, 1200) / 10,
                    'unit' => $units[array_rand($units)],
                    'is_optional' => $j >= 4,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($ingredientRows, 3000) as $chunk) {
            DB::table('recipe_ingredients')->insert($chunk);
        }
    }

    /**
     * @param  Collection<int, int>  $masterProductIds
     */
    private function seedRestaurantProducts(int $perCategory, Collection $masterProductIds): void
    {
        if ($masterProductIds->isEmpty()) {
            return;
        }

        $dishNames = ['برغر لحم', 'شاورما دجاج', 'بيتزا مارجريتا', 'بيتزا خضار', 'كباب مشوي', 'مشاوي مشكلة', 'حمص بالطحينة', 'سلطة يونانية'];
        $categories = DB::table('categories')->select('id', 'restaurant_id')->get();

        DB::table('products')->where('slug', 'like', 'ai-r%')->delete();

        $rows = [];
        foreach ($categories as $category) {
            for ($i = 1; $i <= $perCategory; $i++) {
                $dish = $dishNames[($i - 1) % count($dishNames)];
                $rows[] = [
                    'restaurant_id' => $category->restaurant_id,
                    'category_id' => $category->id,
                    'master_product_id' => $masterProductIds->random(),
                    'name' => sprintf('%s - وجبة %d', $dish, $i),
                    'slug' => sprintf('ai-r%d-c%d-p%03d', $category->restaurant_id, $category->id, $i),
                    'description' => sprintf('طبق مطعم واقعي مناسب للطلبات اليومية (%d).', $i),
                    'price' => random_int(12, 160),
                    'discounted_price' => random_int(0, 1) === 1 ? random_int(9, 140) : null,
                    'is_available' => true,
                    'stock_quantity' => random_int(10, 500),
                    'low_stock_threshold' => random_int(5, 25),
                    'preparation_time' => random_int(8, 55),
                    'is_featured' => random_int(0, 10) > 7,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('products')->insert($chunk);
        }
    }

    private function seedRestaurantOffers(int $perRestaurant): void
    {
        $offerTitles = ['عرض العائلة', 'خصم الغداء', 'عرض نهاية الأسبوع', 'عرض الطلاب', 'توصيل مجاني', 'عرض 2+1'];
        $restaurantIds = DB::table('restaurants')->pluck('id');

        DB::table('offers')->where('name', 'like', 'عرض تطوير مطعم %')->delete();

        $rows = [];
        foreach ($restaurantIds as $restaurantId) {
            for ($i = 1; $i <= $perRestaurant; $i++) {
                $title = $offerTitles[($i - 1) % count($offerTitles)];
                $rows[] = [
                    'restaurant_id' => $restaurantId,
                    'name' => sprintf('عرض تطوير مطعم %s %d', $title, $i),
                    'discount_type' => random_int(0, 1) ? 'percentage' : 'fixed_amount',
                    'discount_value' => random_int(5, 35),
                    'starts_at' => now()->subDays(random_int(1, 90)),
                    'ends_at' => now()->addDays(random_int(7, 180)),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('offers')->insert($chunk);
        }
    }

    /**
     * @param  Collection<int, int>  $masterProductIds
     */
    private function seedSupermarketProducts(int $perCategory, Collection $masterProductIds): void
    {
        if ($masterProductIds->isEmpty()) {
            return;
        }

        $smNames = ['حليب كامل الدسم', 'رز بسمتي', 'سكر أبيض', 'زيت نباتي', 'دجاج كامل', 'لحم مفروم', 'طماطم طازجة', 'خيار طازج', 'بطاطس', 'معكرونة'];
        $categories = DB::table('sm_categories')->select('id', 'store_id')->get();

        DB::table('sm_products')->where('name', 'like', 'منتج تطوير متجر %')->delete();

        $rows = [];
        foreach ($categories as $category) {
            for ($i = 1; $i <= $perCategory; $i++) {
                $base = $smNames[($i - 1) % count($smNames)];
                $rows[] = [
                    'store_id' => $category->store_id,
                    'category_id' => $category->id,
                    'master_product_id' => $masterProductIds->random(),
                    'name' => sprintf('منتج تطوير متجر %s - عبوة %d', $base, $i),
                    'barcode' => sprintf('990%d%d%04d', $category->store_id, $category->id, $i),
                    'source_type' => 'bulk_import',
                    'description' => sprintf('منتج سوبرماركت واقعي للتجارب الذكية (%d).', $i),
                    'price' => random_int(2, 120),
                    'discounted_price' => random_int(0, 1) === 1 ? random_int(1, 100) : null,
                    'stock_quantity' => random_int(20, 1200),
                    'low_stock_threshold' => random_int(5, 40),
                    'expires_at' => now()->addDays(random_int(10, 365)),
                    'is_available' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('sm_products')->insert($chunk);
        }
    }

    private function seedSupermarketOffers(int $perStore): void
    {
        $offerTitles = ['عرض الألبان', 'عرض الخضار', 'عرض اللحوم', 'تخفيض نهاية الأسبوع', 'عرض السلة العائلية', 'خصم 20٪'];
        $storeIds = DB::table('sm_stores')->pluck('id');

        DB::table('sm_offers')->where('name', 'like', 'عرض تطوير متجر %')->delete();

        $rows = [];
        foreach ($storeIds as $storeId) {
            for ($i = 1; $i <= $perStore; $i++) {
                $title = $offerTitles[($i - 1) % count($offerTitles)];
                $rows[] = [
                    'store_id' => $storeId,
                    'name' => sprintf('عرض تطوير متجر %s %d', $title, $i),
                    'description' => sprintf('عرض سوبرماركت واقعي ضمن بيانات التطوير (%d).', $i),
                    'offer_type' => 'percentage',
                    'discount_value' => null,
                    'discount_percent' => random_int(5, 45),
                    'starts_at' => now()->subDays(random_int(1, 45)),
                    'ends_at' => now()->addDays(random_int(10, 180)),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('sm_offers')->insert($chunk);
        }
    }
}
