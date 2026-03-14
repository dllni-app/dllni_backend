<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Enums\UserModuleType;
use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\PriceRange;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

final class RestaurantSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::updateOrCreate(
            ['email' => 'restaurant.owner@example.com'],
            [
                'name' => 'مالك المطعم',
                'phone' => '+963933000001',
                'password' => bcrypt('password'),
                'module_type' => UserModuleType::RestaurantSeller->value,
                'email_verified_at' => now(),
            ]
        );

        $restaurants = [
            [
                'name' => 'مطبخ لا بيازا الإيطالي',
                'slug' => 'la-piazza-italian',
                'description' => 'مطبخ إيطالي أصيل مع بيتزا بالخشب ومعكرونة منزلية. وصفات عائلية تتوارثها الأجيال.',
                'address' => '15 شارع المطاعم، وسط البلد',
                'city' => 'عمّان',
                'district' => 'العبدلي',
                'location_details' => 'قرب البوليفارد، مقابل مجمع المطاعم الخارجي',
                'latitude' => 31.963158,
                'longitude' => 35.930359,
                'phone' => '+962 6 555 1234',
                'whatsapp_number' => '+962 79 555 1234',
                'email' => 'info@lapiazza.example.com',
                'instagram_username' => 'lapiazza.jo',
                'facebook_page_name' => 'La Piazza Jo',
                'price_range' => PriceRange::Medium->value,
                'minimum_order_amount' => 15.00,
                'estimated_preparation_time' => 25,
                'is_featured' => true,
            ],
            [
                'name' => 'التنين الذهبي - بسترو آسيوي',
                'slug' => 'golden-dragon-asian',
                'description' => 'مطبخ آسيوي عصري مع سوشي ودمبلنغ وأطباق الووك. مكونات طازجة يومياً.',
                'address' => '88 شارع الطعام، الحي الغربي',
                'city' => 'عمّان',
                'district' => 'دابوق',
                'location_details' => 'الطابق الثاني فوق مقهى معروف في الشارع الرئيسي',
                'latitude' => 31.970000,
                'longitude' => 35.935000,
                'phone' => '+962 6 555 5678',
                'whatsapp_number' => '+962 79 555 5678',
                'email' => 'contact@goldendragon.example.com',
                'instagram_username' => 'goldendragon.jo',
                'facebook_page_name' => 'Golden Dragon Jo',
                'price_range' => PriceRange::High->value,
                'minimum_order_amount' => 20.00,
                'estimated_preparation_time' => 30,
                'is_featured' => true,
            ],
            [
                'name' => 'ملاذ البرغر',
                'slug' => 'burger-haven',
                'description' => 'برغر فاخر وبطاطس مقطعة يدوياً. خدمة سريعة للاستلام وتناول الطعام.',
                'address' => '42 شارع الوجبات السريعة',
                'city' => 'عمّان',
                'district' => 'الجبيهة',
                'location_details' => 'بجانب الجامعة، مقابل محطة الوقود الرئيسية',
                'latitude' => 31.955000,
                'longitude' => 35.925000,
                'phone' => '+962 6 555 9012',
                'whatsapp_number' => '+962 79 555 9012',
                'email' => 'hello@burgerhaven.example.com',
                'instagram_username' => 'burgerhaven.jo',
                'facebook_page_name' => 'Burger Haven Jo',
                'price_range' => PriceRange::Low->value,
                'minimum_order_amount' => 10.00,
                'estimated_preparation_time' => 15,
                'is_featured' => false,
            ],
        ];

        $cancellationPolicy = CancellationPolicy::where('module', 'restaurant')->where('is_default', true)->first();

        $cuisineTypeIds = $this->ensureCuisineTypes();

        foreach ($restaurants as $data) {
            $restaurant = Restaurant::firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'user_id' => $owner->id,
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'address' => $data['address'],
                    'city' => $data['city'],
                    'district' => $data['district'],
                    'location_details' => $data['location_details'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'phone' => $data['phone'],
                    'whatsapp_number' => $data['whatsapp_number'],
                    'email' => $data['email'],
                    'instagram_username' => $data['instagram_username'],
                    'facebook_page_name' => $data['facebook_page_name'],
                    'price_range' => $data['price_range'],
                    'minimum_order_amount' => $data['minimum_order_amount'],
                    'estimated_preparation_time' => $data['estimated_preparation_time'],
                    'average_rating' => fake()->randomFloat(2, 4.0, 4.9),
                    'total_reviews' => fake()->numberBetween(50, 500),
                    'reputation_score' => fake()->numberBetween(85, 100),
                    'visibility_score' => 100,
                    'is_active' => true,
                    'is_featured' => $data['is_featured'],
                    'is_temporarily_closed' => false,
                ]
            );

            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($days as $i => $day) {
                DB::table('operating_hours')->updateOrInsert(
                    [
                        'restaurant_id' => $restaurant->id,
                        'day_of_week' => $day,
                    ],
                    [
                        'open_time' => $i < 5 ? '11:00' : '12:00',
                        'close_time' => $i < 5 ? '22:00' : '23:00',
                        'is_closed' => false,
                        'updated_at' => now(),
                    ]
                );
            }

            $categories = [
                ['name' => 'مقبلات', 'slug' => 'starters'],
                ['name' => 'الطبق الرئيسي', 'slug' => 'main-course'],
                ['name' => 'حلويات', 'slug' => 'desserts'],
                ['name' => 'مشروبات', 'slug' => 'drinks'],
            ];

            foreach ($categories as $i => $cat) {
                $category = DB::table('categories')->where('restaurant_id', $restaurant->id)->where('slug', $cat['slug'])->first();
                if (! $category) {
                    $categoryId = DB::table('categories')->insertGetId([
                        'restaurant_id' => $restaurant->id,
                        'name' => $cat['name'],
                        'slug' => $cat['slug'],
                        'sort_order' => $i + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $products = $this->getProductsForCategory($restaurant->id, $categoryId, $cat['slug']);
                    foreach ($products as $product) {
                        DB::table('products')->insert($product);
                    }
                }
            }

            $cuisineMap = [
                'la-piazza-italian' => 'italian',
                'golden-dragon-asian' => 'asian',
                'burger-haven' => 'american',
            ];
            $cuisineSlug = $cuisineMap[$data['slug']] ?? 'italian';
            $cuisineId = $cuisineTypeIds[$cuisineSlug] ?? $cuisineTypeIds['italian'];
            DB::table('cuisine_type_restaurant')->insertOrIgnore([
                'cuisine_type_id' => $cuisineId,
                'restaurant_id' => $restaurant->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->seedInventoryItems($restaurant);

            $this->seedSampleOrders($restaurant, $owner, $cancellationPolicy);
            $this->seedRequestedRestaurantData($restaurant);
            $this->seedOwnerAppData($restaurant, $owner);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProductsForCategory(int $restaurantId, int $categoryId, string $categorySlug): array
    {
        $baseProducts = match ($categorySlug) {
            'starters' => [
                ['name' => 'سلطة سيزر', 'price' => 8.99],
                ['name' => 'شوربة اليوم', 'price' => 6.50],
                ['name' => 'خبز بالثوم', 'price' => 5.99],
                ['name' => 'بروشيتا', 'price' => 7.50],
            ],
            'main-course' => [
                ['name' => 'دجاج مشوي', 'price' => 14.99],
                ['name' => 'برغر لحم', 'price' => 12.99],
                ['name' => 'باستا كاربونارا', 'price' => 13.50],
                ['name' => 'سمك ورقاص', 'price' => 15.99],
            ],
            'desserts' => [
                ['name' => 'كيك شوكولاتة', 'price' => 7.99],
                ['name' => 'آيس كريم', 'price' => 5.50],
                ['name' => 'تيراميسو', 'price' => 8.99],
            ],
            'drinks' => [
                ['name' => 'عصير طازج', 'price' => 4.50],
                ['name' => 'مشروب غازي', 'price' => 2.99],
                ['name' => 'قهوة', 'price' => 3.50],
                ['name' => 'ماء', 'price' => 1.50],
            ],
            default => [],
        };

        $products = [];
        foreach ($baseProducts as $p) {
            $products[] = [
                'restaurant_id' => $restaurantId,
                'category_id' => $categoryId,
                'name' => $p['name'],
                'description' => null,
                'price' => $p['price'],
                'discounted_price' => null,
                'is_available' => true,
                'stock_quantity' => 100,
                'low_stock_threshold' => 5,
                'preparation_time' => 10,
                'is_featured' => fake()->boolean(30),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $products;
    }

    /**
     * @return array<string, int>
     */
    private function ensureCuisineTypes(): array
    {
        $types = [
            'italian' => ['name' => 'إيطالي', 'slug' => 'italian'],
            'asian' => ['name' => 'آسيوي', 'slug' => 'asian'],
            'american' => ['name' => 'أمريكي', 'slug' => 'american'],
            'mediterranean' => ['name' => 'متوسطي', 'slug' => 'mediterranean'],
        ];

        $ids = [];
        foreach ($types as $key => $data) {
            $id = DB::table('cuisine_types')->where('slug', $data['slug'])->value('id');
            if (! $id) {
                $id = DB::table('cuisine_types')->insertGetId([
                    ...$data,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $ids[$key] = $id;
        }

        return $ids;
    }

    private function seedSampleOrders(Restaurant $restaurant, User $owner, ?CancellationPolicy $cancellationPolicy): void
    {
        $customer = User::firstOrCreate(
            ['email' => 'restaurant.customer@example.com'],
            [
                'name' => 'عميل المطعم',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        for ($i = 0; $i < 5; $i++) {
            $orderNumber = 'ORD-'.mb_strtoupper(Str::random(6)).$i;
            if (Order::where('order_number', $orderNumber)->exists()) {
                continue;
            }

            $subtotal = fake()->randomFloat(2, 25, 80);
            $taxAmount = round($subtotal * 0.1, 2);
            $totalAmount = $subtotal + $taxAmount;

            Order::create([
                'user_id' => $customer->id,
                'restaurant_id' => $restaurant->id,
                'cancellation_policy_id' => $cancellationPolicy?->id,
                'order_number' => $orderNumber,
                'status' => OrderStatus::Completed->value,
                'order_type' => OrderType::Pickup->value,
                'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => $taxAmount,
                'service_fee' => 0,
                'total_amount' => $totalAmount,
                'accepted_at' => now()->subDays($i),
                'completed_at' => now()->subDays($i)->addMinutes(25),
            ]);
        }
    }

    private function seedRequestedRestaurantData(Restaurant $restaurant): void
    {
        if ($restaurant->id !== 1) {
            return;
        }

        $categories = [
            ['id' => 1, 'name' => 'المقبلات', 'slug' => 'appetizers', 'sort_order' => 1],
            ['id' => 2, 'name' => 'الأطباق الرئيسية', 'slug' => 'main-dishes', 'sort_order' => 2],
            ['id' => 3, 'name' => 'المشروبات', 'slug' => 'drinks', 'sort_order' => 3],
            ['id' => 4, 'name' => 'البيتزا', 'slug' => 'pizza', 'sort_order' => 4],
        ];

        foreach ($categories as $category) {
            DB::table('categories')->updateOrInsert(
                ['id' => $category['id']],
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'sort_order' => $category['sort_order'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $products = [
            ['id' => 1, 'category_id' => 4, 'name' => 'بيتزا مارجريتا', 'description' => 'بيتزا بجبنة موزاريلا', 'price' => 35, 'discounted_price' => 30, 'is_available' => true, 'stock_quantity' => 50],
            ['id' => 2, 'category_id' => 2, 'name' => 'دجاج مشوي', 'description' => 'دجاج متبل ومشوي على الفحم', 'price' => 40, 'discounted_price' => null, 'is_available' => true, 'stock_quantity' => 20],
            ['id' => 3, 'category_id' => 1, 'name' => 'سلطة خضار', 'description' => 'سلطة طازجة يومياً', 'price' => 15, 'discounted_price' => null, 'is_available' => true, 'stock_quantity' => 30],
            ['id' => 4, 'category_id' => 3, 'name' => 'حليب بارد', 'description' => 'حليب كامل الدسم مبرد', 'price' => 5, 'discounted_price' => null, 'is_available' => true, 'stock_quantity' => 100],
        ];

        foreach ($products as $product) {
            DB::table('products')->updateOrInsert(
                ['id' => $product['id']],
                [
                    'restaurant_id' => $restaurant->id,
                    'category_id' => $product['category_id'],
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'price' => $product['price'],
                    'discounted_price' => $product['discounted_price'],
                    'is_available' => $product['is_available'],
                    'stock_quantity' => $product['stock_quantity'],
                    'low_stock_threshold' => 5,
                    'preparation_time' => 10,
                    'is_featured' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $offers = [
            ['id' => 1, 'name' => 'عرض البيتزا العائلية', 'is_active' => true],
            ['id' => 2, 'name' => 'خصم 10% على المشروبات', 'is_active' => true],
        ];

        foreach ($offers as $offer) {
            DB::table('offers')->updateOrInsert(
                ['id' => $offer['id']],
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => $offer['name'],
                    'discount_type' => 'percentage',
                    'discount_value' => 10,
                    'starts_at' => null,
                    'ends_at' => null,
                    'is_active' => $offer['is_active'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function seedInventoryItems(Restaurant $restaurant): void
    {
        $itemsBySlug = [
            'la-piazza-italian' => [
                [
                    'name' => 'طحين بيتزا من نوع كابوتو',
                    'unit' => 'kg',
                    'quantity' => 120,
                    'minimum_limit' => 40,
                    'unit_cost' => 1.8,
                ],
                [
                    'name' => 'جبنة موزاريلا طازجة',
                    'unit' => 'kg',
                    'quantity' => 60,
                    'minimum_limit' => 20,
                    'unit_cost' => 4.5,
                ],
                [
                    'name' => 'صلصة طماطم إيطالية معلبة',
                    'unit' => 'علبة',
                    'quantity' => 80,
                    'minimum_limit' => 25,
                    'unit_cost' => 1.2,
                ],
            ],
            'golden-dragon-asian' => [
                [
                    'name' => 'أرز بسمتي طويل الحبة',
                    'unit' => 'kg',
                    'quantity' => 90,
                    'minimum_limit' => 30,
                    'unit_cost' => 2.3,
                ],
                [
                    'name' => 'صلصة صويا داكنة',
                    'unit' => 'لتر',
                    'quantity' => 25,
                    'minimum_limit' => 8,
                    'unit_cost' => 3.1,
                ],
                [
                    'name' => 'نودلز قمح للوجبات السريعة',
                    'unit' => 'كرتونة',
                    'quantity' => 15,
                    'minimum_limit' => 5,
                    'unit_cost' => 12.0,
                ],
            ],
            'burger-haven' => [
                [
                    'name' => 'لحم برغر مفروم طازج',
                    'unit' => 'kg',
                    'quantity' => 70,
                    'minimum_limit' => 25,
                    'unit_cost' => 6.8,
                ],
                [
                    'name' => 'خبز برغر سلايدر',
                    'unit' => 'علبة',
                    'quantity' => 40,
                    'minimum_limit' => 15,
                    'unit_cost' => 3.0,
                ],
                [
                    'name' => 'بطاطس مقلية مجمدة',
                    'unit' => 'كيس',
                    'quantity' => 55,
                    'minimum_limit' => 18,
                    'unit_cost' => 2.6,
                ],
            ],
        ];

        $items = $itemsBySlug[$restaurant->slug] ?? [];

        foreach ($items as $item) {
            InventoryItem::firstOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => $item['name'],
                ],
                [
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'minimum_limit' => $item['minimum_limit'],
                    'unit_cost' => $item['unit_cost'],
                ]
            );
        }
    }

    private function seedOwnerAppData(Restaurant $restaurant, User $owner): void
    {
        $managerRoleId = DB::table('restaurant_roles')->where([
            'restaurant_id' => $restaurant->id,
            'slug' => 'manager',
        ])->value('id');

        if (! $managerRoleId) {
            $managerRoleId = DB::table('restaurant_roles')->insertGetId([
                'restaurant_id' => $restaurant->id,
                'name' => 'مدير',
                'slug' => 'manager',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $cashierRoleId = DB::table('restaurant_roles')->where([
            'restaurant_id' => $restaurant->id,
            'slug' => 'cashier',
        ])->value('id');

        if (! $cashierRoleId) {
            $cashierRoleId = DB::table('restaurant_roles')->insertGetId([
                'restaurant_id' => $restaurant->id,
                'name' => 'كاشير',
                'slug' => 'cashier',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $employeeOne = User::firstOrCreate(
            ['email' => "employee.one+{$restaurant->id}@example.com"],
            [
                'name' => 'موظف أول',
                'phone' => '+962790000001'.$restaurant->id,
                'password' => bcrypt('password'),
                'module_type' => UserModuleType::RestaurantSeller->value,
                'email_verified_at' => now(),
            ]
        );

        $employeeTwo = User::firstOrCreate(
            ['email' => "employee.two+{$restaurant->id}@example.com"],
            [
                'name' => 'موظف ثاني',
                'phone' => '+962790000002'.$restaurant->id,
                'password' => bcrypt('password'),
                'module_type' => UserModuleType::RestaurantSeller->value,
                'email_verified_at' => now(),
            ]
        );

        DB::table('restaurant_staff')->updateOrInsert(
            [
                'restaurant_id' => $restaurant->id,
                'user_id' => $employeeOne->id,
            ],
            [
                'restaurant_role_id' => $managerRoleId,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('restaurant_staff')->updateOrInsert(
            [
                'restaurant_id' => $restaurant->id,
                'user_id' => $employeeTwo->id,
            ],
            [
                'restaurant_role_id' => $cashierRoleId,
                'is_active' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $activeCouponId = DB::table('promo_codes')->where([
            'restaurant_id' => $restaurant->id,
            'code' => 'SAVE25-'.$restaurant->id,
        ])->value('id');

        if (! $activeCouponId) {
            $activeCouponId = DB::table('promo_codes')->insertGetId([
                'restaurant_id' => $restaurant->id,
                'code' => 'SAVE25-'.$restaurant->id,
                'discount_type' => 'percentage',
                'discount_value' => 25,
                'min_order_amount' => 20,
                'usage_limit' => 200,
                'usage_count' => 35,
                'starts_at' => now()->subDays(3),
                'ends_at' => now()->addDays(7),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('promo_codes')->updateOrInsert(
            [
                'restaurant_id' => $restaurant->id,
                'code' => 'OLD10-'.$restaurant->id,
            ],
            [
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'min_order_amount' => 10,
                'usage_limit' => 100,
                'usage_count' => 52,
                'starts_at' => now()->subDays(12),
                'ends_at' => now()->subDays(2),
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $activeOfferId = DB::table('offers')->where([
            'restaurant_id' => $restaurant->id,
            'name' => 'عرض الوجبات العائلية',
        ])->value('id');

        if (! $activeOfferId) {
            $activeOfferId = DB::table('offers')->insertGetId([
                'restaurant_id' => $restaurant->id,
                'name' => 'عرض الوجبات العائلية',
                'discount_type' => 'percentage',
                'discount_value' => 15,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(4),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('offers')->updateOrInsert(
            [
                'restaurant_id' => $restaurant->id,
                'name' => 'عرض نهاية الأسبوع',
            ],
            [
                'discount_type' => 'percentage',
                'discount_value' => 20,
                'starts_at' => now()->addDays(2),
                'ends_at' => now()->addDays(5),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $productIds = DB::table('products')->where('restaurant_id', $restaurant->id)->limit(3)->pluck('id')->all();
        foreach ($productIds as $productId) {
            DB::table('offer_product')->updateOrInsert(
                [
                    'offer_id' => $activeOfferId,
                    'product_id' => $productId,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        DB::table('products')
            ->where('restaurant_id', $restaurant->id)
            ->inRandomOrder()
            ->limit(1)
            ->update([
                'is_available' => false,
                'unavailable_until' => now()->endOfDay(),
                'availability_note' => 'نفدت الكمية اليوم',
                'updated_at' => now(),
            ]);

        $sampleOrderId = DB::table('orders')
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('id')
            ->value('id');

        if ($sampleOrderId) {
            $sampleProductId = DB::table('products')->where('restaurant_id', $restaurant->id)->value('id');
            if ($sampleProductId) {
                DB::table('order_items')->updateOrInsert(
                    [
                        'order_id' => $sampleOrderId,
                        'product_id' => $sampleProductId,
                    ],
                    [
                        'quantity' => 2,
                        'unit_price' => 12,
                        'total_price' => 24,
                        'special_instructions' => 'بدون بصل',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            DB::table('system_alerts')->updateOrInsert(
                [
                    'booking_id' => $sampleOrderId,
                    'booking_type' => Order::class,
                    'alert_type' => AlertType::OverdueCompletion->value,
                ],
                [
                    'severity' => AlertSeverity::Medium->value,
                    'status' => SystemAlertStatus::New->value,
                    'payload' => json_encode(['order_id' => $sampleOrderId], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $seedNotificationType = 'App\\Notifications\\RestaurantOwnerSeedNotification';
        $exists = DB::table('notifications')
            ->where('type', $seedNotificationType)
            ->where('notifiable_type', $owner->getMorphClass())
            ->where('notifiable_id', $owner->id)
            ->exists();

        if (! $exists) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => $seedNotificationType,
                'notifiable_type' => $owner->getMorphClass(),
                'notifiable_id' => $owner->id,
                'data' => json_encode([
                    'type' => 'new_offer',
                    'title' => 'عرض جديد نشط',
                    'body' => 'تم تفعيل عرض جديد لهذا المطعم.',
                ], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
