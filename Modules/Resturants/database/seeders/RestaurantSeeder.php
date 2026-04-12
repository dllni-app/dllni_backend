<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Enums\UserModuleType;
use App\Models\CancellationPolicy;
use App\Models\User;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\PriceRange;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\Review;
use Throwable;

final class RestaurantSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::updateOrCreate(
            ['email' => 'restaurant.owner@dllni.sy'],
            [
                'name' => '�.ا�"�f ا�"�.طع�.',
                'phone' => '+963933000001',
                'password' => bcrypt('password'),
                'module_type' => UserModuleType::RestaurantSeller->value,
                'email_verified_at' => now(),
            ]
        );

        $restaurants = [
            [
                'name' => '�.طبخ �"ا ب�Sازا ا�"إ�Sطا�"�S',
                'slug' => 'la-piazza-italian',
                'description' => '�.طبخ إ�Sطا�"�S أص�S�" �.ع ب�Sتزا با�"خشب �^�.ع�fر�^�?ة �.�?ز�"�Sة. �^صفات عائ�"�Sة تت�^ارث�?ا ا�"أج�Sا�".',
                'address' => '15 شارع ا�"�.طاع�.�O ا�"فر�,ا�?',
                'city' => 'ح�"ب',
                'district' => 'ا�"فر�,ا�?',
                'location_details' => '�,رب د�^ار ا�"صخرة�O �.�,اب�" �.ج�.ع ا�"�.طاع�.',
                'latitude' => 36.2021,
                'longitude' => 37.1343,
                'phone' => '+963 21 555 1234',
                'whatsapp_number' => '+963 944 555 1234',
                'email' => 'info@lapiazza.dllni.sy',
                'instagram_username' => 'lapiazza.aleppo',
                'facebook_page_name' => 'La Piazza Aleppo',
                'price_range' => PriceRange::Medium->value,
                'minimum_order_amount' => 15.00,
                'estimated_preparation_time' => 25,
                'is_featured' => true,
            ],
            [
                'name' => 'ا�"ت�?�S�? ا�"ذ�?ب�S - بستر�^ آس�S�^�S',
                'slug' => 'golden-dragon-asian',
                'description' => '�.طبخ آس�S�^�S عصر�S �.ع س�^ش�S �^د�.ب�"�?غ �^أطبا�, ا�"�^�^�f. �.�f�^�?ات طازجة �S�^�.�Sا�<.',
                'address' => '88 شارع ا�"�.طاع�.�O ا�"ج�.�S�"�Sة',
                'city' => 'ح�"ب',
                'district' => 'ا�"ج�.�S�"�Sة',
                'location_details' => 'ا�"طاب�, ا�"ثا�?�S ف�^�, �.�,�?�? �.عر�^ف ف�S ا�"شارع ا�"رئ�Sس�S',
                'latitude' => 36.2127,
                'longitude' => 37.1456,
                'phone' => '+963 21 555 5678',
                'whatsapp_number' => '+963 944 555 5678',
                'email' => 'contact@goldendragon.dllni.sy',
                'instagram_username' => 'goldendragon.aleppo',
                'facebook_page_name' => 'Golden Dragon Aleppo',
                'price_range' => PriceRange::High->value,
                'minimum_order_amount' => 20.00,
                'estimated_preparation_time' => 30,
                'is_featured' => true,
            ],
            [
                'name' => '�.�"اذ ا�"برغر',
                'slug' => 'burger-haven',
                'description' => 'برغر فاخر �^بطاطس �.�,طعة �Sد�^�Sا�<. خد�.ة سر�Sعة �"�"است�"ا�. �^ت�?ا�^�" ا�"طعا�..',
                'address' => '42 شارع ا�"�^جبات ا�"سر�Sعة',
                'city' => 'ح�"ب',
                'district' => 'ا�"أشرف�Sة',
                'location_details' => 'بجا�?ب ا�"جا�.عة�O �.�,اب�" �.حطة ا�"�^�,�^د ا�"رئ�Sس�Sة',
                'latitude' => 36.2308,
                'longitude' => 37.1279,
                'phone' => '+963 21 555 9012',
                'whatsapp_number' => '+963 944 555 9012',
                'email' => 'hello@burgerhaven.dllni.sy',
                'instagram_username' => 'burgerhaven.aleppo',
                'facebook_page_name' => 'Burger Haven Aleppo',
                'price_range' => PriceRange::Low->value,
                'minimum_order_amount' => 10.00,
                'estimated_preparation_time' => 15,
                'is_featured' => false,
            ],
            [
                'name' => 'ب�Sت ا�"شا�. �"�"�.شا�^�S',
                'slug' => 'beit-al-sham-grill',
                'description' => '�.طبخ شا�.�S �S�,د�. ا�"�.شا�^�S �^ا�"�.�,ب�"ات ا�"شر�,�Sة ب�^صفات أص�S�"ة.',
                'address' => '10 شارع ا�"ر�^ضة�O ا�"سر�Sا�? ا�"جد�Sدة',
                'city' => 'ح�"ب',
                'district' => 'ا�"سر�Sا�? ا�"جد�Sدة',
                'location_details' => '�,رب ساحة سعد ا�"�"�? ا�"جابر�S�O بجا�?ب �.ر�fز ا�"تس�^�,',
                'latitude' => 36.2168,
                'longitude' => 37.1317,
                'phone' => '+963 21 555 7788',
                'whatsapp_number' => '+963 944 555 7788',
                'email' => 'hello@beitsham.dllni.sy',
                'instagram_username' => 'beitsham.aleppo',
                'facebook_page_name' => 'Beit Al Sham Aleppo',
                'price_range' => PriceRange::Medium->value,
                'minimum_order_amount' => 18.00,
                'estimated_preparation_time' => 28,
                'is_featured' => true,
            ],
            [
                'name' => 'فط�^ر ا�"س�?د�Sا�?',
                'slug' => 'cedar-breakfast-house',
                'description' => 'إفطار عرب�S �S�^�.�S �.ع �.�?ا�,�Sش طازجة �^�^جبات خف�Sفة ط�^ا�" ا�"�S�^�..',
                'address' => '5 شارع ا�"حدائ�,�O ا�"ح�.دا�?�Sة',
                'city' => 'ح�"ب',
                'district' => 'ا�"ح�.دا�?�Sة',
                'location_details' => '�,رب ا�"إشارة ا�"رئ�Sس�Sة�O ا�"طاب�, ا�"أرض�S',
                'latitude' => 36.1795,
                'longitude' => 37.1082,
                'phone' => '+963 21 555 8899',
                'whatsapp_number' => '+963 944 555 8899',
                'email' => 'contact@cedarbreakfast.dllni.sy',
                'instagram_username' => 'cedarbreakfast.aleppo',
                'facebook_page_name' => 'Cedar Breakfast Aleppo',
                'price_range' => PriceRange::Low->value,
                'minimum_order_amount' => 8.00,
                'estimated_preparation_time' => 14,
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
                $allDayOpen = $restaurant->slug === 'la-piazza-italian';
                DB::table('operating_hours')->updateOrInsert(
                    [
                        'restaurant_id' => $restaurant->id,
                        'day_of_week' => $day,
                    ],
                    [
                        'open_time' => $allDayOpen ? '00:00' : ($i < 5 ? '11:00' : '12:00'),
                        'close_time' => $allDayOpen ? '23:59' : ($i < 5 ? '22:00' : '23:00'),
                        'is_closed' => false,
                        'updated_at' => now(),
                    ]
                );
            }

            $categories = [
                ['name' => '�.�,ب�"ات', 'slug' => 'starters'],
                ['name' => 'ا�"طب�, ا�"رئ�Sس�S', 'slug' => 'main-course'],
                ['name' => 'ح�"�^�Sات', 'slug' => 'desserts'],
                ['name' => '�.شر�^بات', 'slug' => 'drinks'],
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
                'beit-al-sham-grill' => 'mediterranean',
                'cedar-breakfast-house' => 'mediterranean',
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
            $this->seedReviews($restaurant);
            $this->seedModifierGroups($restaurant);
            $this->seedProductSubstitutions($restaurant);
            $this->seedProductImages($restaurant);
            $this->seedRestaurantImages($restaurant);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProductsForCategory(int $restaurantId, int $categoryId, string $categorySlug): array
    {
        $baseProducts = match ($categorySlug) {
            'starters' => [
                ['name' => 'س�"طة س�Sزر', 'price' => 8.99],
                ['name' => 'ش�^ربة ا�"�S�^�.', 'price' => 6.50],
                ['name' => 'خبز با�"ث�^�.', 'price' => 5.99],
                ['name' => 'بر�^ش�Sتا', 'price' => 7.50],
            ],
            'main-course' => [
                ['name' => 'دجاج �.ش�^�S', 'price' => 14.99],
                ['name' => 'برغر �"ح�.', 'price' => 12.99],
                ['name' => 'باستا �fارب�^�?ارا', 'price' => 13.50],
                ['name' => 'س�.�f �^ر�,اص', 'price' => 15.99],
            ],
            'desserts' => [
                ['name' => '�f�S�f ش�^�f�^�"اتة', 'price' => 7.99],
                ['name' => 'آ�Sس �fر�S�.', 'price' => 5.50],
                ['name' => 'ت�Sرا�.�Sس�^', 'price' => 8.99],
            ],
            'drinks' => [
                ['name' => 'عص�Sر طازج', 'price' => 4.50],
                ['name' => '�.شر�^ب غاز�S', 'price' => 2.99],
                ['name' => '�,�?�^ة', 'price' => 3.50],
                ['name' => '�.اء', 'price' => 1.50],
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
            'italian' => ['name' => 'إ�Sطا�"�S', 'slug' => 'italian'],
            'asian' => ['name' => 'آس�S�^�S', 'slug' => 'asian'],
            'american' => ['name' => 'أ�.ر�S�f�S', 'slug' => 'american'],
            'mediterranean' => ['name' => '�.ت�^سط�S', 'slug' => 'mediterranean'],
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
            ['email' => 'restaurant.customer@dllni.sy'],
            [
                'name' => 'ع�.�S�" ا�"�.طع�.',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $statusTemplates = [
            ['status' => OrderStatus::Completed, 'daysAgo' => 1],
            ['status' => OrderStatus::Completed, 'daysAgo' => 2],
            ['status' => OrderStatus::PickedUp, 'daysAgo' => 3],
            ['status' => OrderStatus::Completed, 'daysAgo' => 4],
            ['status' => OrderStatus::PickedUp, 'daysAgo' => 5],
            ['status' => OrderStatus::Accepted, 'daysAgo' => 0],
            ['status' => OrderStatus::Cancelled, 'daysAgo' => 2],
            ['status' => OrderStatus::Completed, 'daysAgo' => 6],
        ];

        foreach ($statusTemplates as $index => $template) {
            $status = $template['status'];
            $baseTime = now()->subDays((int) $template['daysAgo'])->setTime(12, 0);
            $subtotal = round(18 + ($restaurant->id * 1.75) + ($index * 2.25), 2);
            $taxAmount = round($subtotal * 0.1, 2);
            $totalAmount = $subtotal + $taxAmount;

            $acceptedAt = null;
            $preparingAt = null;
            $readyForPickupAt = null;
            $pickedUpAt = null;
            $customerPickupConfirmedAt = null;
            $completedAt = null;
            $cancelledAt = null;
            $cancellationReason = null;

            if ($status === OrderStatus::Accepted) {
                $acceptedAt = $baseTime;
            }

            if ($status === OrderStatus::PickedUp || $status === OrderStatus::Completed) {
                $acceptedAt = $baseTime;
                $preparingAt = $baseTime->copy()->addMinutes(5);
                $readyForPickupAt = $baseTime->copy()->addMinutes(22);
                $pickedUpAt = $baseTime->copy()->addMinutes(32);
                $customerPickupConfirmedAt = $baseTime->copy()->addMinutes(33);
            }

            if ($status === OrderStatus::Completed) {
                $completedAt = $baseTime->copy()->addMinutes(36);
            }

            if ($status === OrderStatus::Cancelled) {
                $acceptedAt = $baseTime;
                $cancelledAt = $baseTime->copy()->addMinutes(12);
                $cancellationReason = 'قام العميل بتغيير خطة الاستلام';
            }

            Order::updateOrCreate(
                ['order_number' => sprintf('RST-%d-%03d', $restaurant->id, $index + 1)],
                [
                    'user_id' => $customer->id,
                    'restaurant_id' => $restaurant->id,
                    'cancellation_policy_id' => $cancellationPolicy?->id,
                    'status' => $status->value,
                    'order_type' => OrderType::Pickup->value,
                    'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
                    'subtotal' => $subtotal,
                    'discount_amount' => 0,
                    'tax_amount' => $taxAmount,
                    'service_fee' => 0,
                    'total_amount' => $totalAmount,
                    'accepted_at' => $acceptedAt,
                    'preparing_at' => $preparingAt,
                    'ready_for_pickup_at' => $readyForPickupAt,
                    'picked_up_at' => $pickedUpAt,
                    'customer_pickup_confirmed_at' => $customerPickupConfirmedAt,
                    'completed_at' => $completedAt,
                    'cancelled_at' => $cancelledAt,
                    'cancellation_reason' => $cancellationReason,
                ]
            );
        }
    }

    private function seedRequestedRestaurantData(Restaurant $restaurant): void
    {
        if ($restaurant->id !== 1) {
            return;
        }

        $categories = [
            ['id' => 1, 'name' => 'ا�"�.�,ب�"ات', 'slug' => 'appetizers', 'sort_order' => 1],
            ['id' => 2, 'name' => 'ا�"أطبا�, ا�"رئ�Sس�Sة', 'slug' => 'main-dishes', 'sort_order' => 2],
            ['id' => 3, 'name' => 'ا�"�.شر�^بات', 'slug' => 'drinks', 'sort_order' => 3],
            ['id' => 4, 'name' => 'ا�"ب�Sتزا', 'slug' => 'pizza', 'sort_order' => 4],
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
            ['id' => 1, 'category_id' => 4, 'name' => 'ب�Sتزا �.ارجر�Sتا', 'description' => 'ب�Sتزا بجب�?ة �.�^زار�S�"ا', 'price' => 35, 'discounted_price' => 30, 'is_available' => true, 'stock_quantity' => 50],
            ['id' => 2, 'category_id' => 2, 'name' => 'دجاج �.ش�^�S', 'description' => 'دجاج �.تب�" �^�.ش�^�S ع�"�? ا�"فح�.', 'price' => 40, 'discounted_price' => null, 'is_available' => true, 'stock_quantity' => 20],
            ['id' => 3, 'category_id' => 1, 'name' => 'س�"طة خضار', 'description' => 'س�"طة طازجة �S�^�.�Sا�<', 'price' => 15, 'discounted_price' => null, 'is_available' => true, 'stock_quantity' => 30],
            ['id' => 4, 'category_id' => 3, 'name' => 'ح�"�Sب بارد', 'description' => 'ح�"�Sب �fا�.�" ا�"دس�. �.برد', 'price' => 5, 'discounted_price' => null, 'is_available' => true, 'stock_quantity' => 100],
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
            ['id' => 1, 'name' => 'عرض ا�"ب�Sتزا ا�"عائ�"�Sة', 'description' => '�^فر أ�fثر ع�?د ط�"ب حج�. ا�"عائ�"ة �.ع تش�f�S�"ة ا�"إضافات.', 'is_active' => true],
            ['id' => 2, 'name' => 'خص�. 10% ع�"�? ا�"�.شر�^بات', 'description' => 'خص�. ف�^ر�S ع�"�? ج�.�Sع ا�"�.شر�^بات ا�"باردة �^ا�"ساخ�?ة.', 'is_active' => true],
        ];

        foreach ($offers as $offer) {
            DB::table('offers')->updateOrInsert(
                ['id' => $offer['id']],
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => $offer['name'],
                    'description' => $offer['description'],
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
                    'name' => 'طح�S�? ب�Sتزا �.�? �?�^ع �fاب�^ت�^',
                    'unit' => 'kg',
                    'quantity' => 120,
                    'minimum_limit' => 40,
                    'unit_cost' => 1.8,
                ],
                [
                    'name' => 'جب�?ة �.�^زار�S�"ا طازجة',
                    'unit' => 'kg',
                    'quantity' => 60,
                    'minimum_limit' => 20,
                    'unit_cost' => 4.5,
                ],
                [
                    'name' => 'ص�"صة ط�.اط�. إ�Sطا�"�Sة �.ع�"بة',
                    'unit' => 'ع�"بة',
                    'quantity' => 80,
                    'minimum_limit' => 25,
                    'unit_cost' => 1.2,
                ],
            ],
            'golden-dragon-asian' => [
                [
                    'name' => 'أرز بس�.ت�S ط�^�S�" ا�"حبة',
                    'unit' => 'kg',
                    'quantity' => 90,
                    'minimum_limit' => 30,
                    'unit_cost' => 2.3,
                ],
                [
                    'name' => 'ص�"صة ص�^�Sا دا�f�?ة',
                    'unit' => '�"تر',
                    'quantity' => 25,
                    'minimum_limit' => 8,
                    'unit_cost' => 3.1,
                ],
                [
                    'name' => '�?�^د�"ز �,�.ح �"�"�^جبات ا�"سر�Sعة',
                    'unit' => '�fرت�^�?ة',
                    'quantity' => 15,
                    'minimum_limit' => 5,
                    'unit_cost' => 12.0,
                ],
            ],
            'burger-haven' => [
                [
                    'name' => '�"ح�. برغر �.فر�^�. طازج',
                    'unit' => 'kg',
                    'quantity' => 70,
                    'minimum_limit' => 25,
                    'unit_cost' => 6.8,
                ],
                [
                    'name' => 'خبز برغر س�"ا�Sدر',
                    'unit' => 'ع�"بة',
                    'quantity' => 40,
                    'minimum_limit' => 15,
                    'unit_cost' => 3.0,
                ],
                [
                    'name' => 'بطاطس �.�,�"�Sة �.ج�.دة',
                    'unit' => '�f�Sس',
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
                'name' => '�.د�Sر',
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
                'name' => '�fاش�Sر',
                'slug' => 'cashier',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $employeeOne = User::firstOrCreate(
            ['email' => "employee.one+{$restaurant->id}@dllni.sy"],
            [
                'name' => '�.�^ظف أ�^�"',
                'phone' => '+963944200001'.$restaurant->id,
                'password' => bcrypt('password'),
                'module_type' => UserModuleType::RestaurantSeller->value,
                'email_verified_at' => now(),
            ]
        );

        $employeeTwo = User::firstOrCreate(
            ['email' => "employee.two+{$restaurant->id}@dllni.sy"],
            [
                'name' => '�.�^ظف ثا�?�S',
                'phone' => '+963944200002'.$restaurant->id,
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
            'name' => 'عرض ا�"�^جبات ا�"عائ�"�Sة',
        ])->value('id');

        if (! $activeOfferId) {
            $activeOfferId = DB::table('offers')->insertGetId([
                'restaurant_id' => $restaurant->id,
                'name' => 'عرض ا�"�^جبات ا�"عائ�"�Sة',
                'description' => 'تخف�Sض خاص ع�"�? �^جبات ا�"عائ�"ة �"فترة �.حد�^دة.',
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
                'name' => 'عرض �?�?ا�Sة ا�"أسب�^ع',
            ],
            [
                'description' => '�^فر أ�fثر خ�"ا�" عط�"ة �?�?ا�Sة ا�"أسب�^ع ع�"�? أص�?اف �.ختارة.',
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
                'availability_note' => '�?فدت ا�"�f�.�Sة ا�"�S�^�.',
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
                        'special_instructions' => 'بد�^�? بص�"',
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
                    'title' => 'عرض جد�Sد �?شط',
                    'body' => 'ت�. تفع�S�" عرض جد�Sد �"�?ذا ا�"�.طع�..',
                ], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedReviews(Restaurant $restaurant): void
    {
        $customer = User::query()->where('email', 'restaurant.customer@dllni.sy')->first();
        if (! $customer) {
            return;
        }

        $orders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('user_id', $customer->id)
            ->latest('id')
            ->limit(3)
            ->get();

        foreach ($orders as $index => $order) {
            Review::updateOrCreate(
                [
                    'user_id' => $customer->id,
                    'order_id' => $order->id,
                ],
                [
                    'restaurant_id' => $restaurant->id,
                    'rating' => match ($index) {
                        0 => 5,
                        1 => 4,
                        default => 5,
                    },
                    'comment' => match ($index) {
                        0 => 'Great taste and fast pickup.',
                        1 => 'Good value, will order again.',
                        default => 'Loved the flavors!',
                    },
                    'updated_at' => now(),
                    'created_at' => $order->created_at ?? now(),
                ]
            );
        }
    }

    private function seedModifierGroups(Restaurant $restaurant): void
    {
        $groups = [
            [
                'name' => 'إضافات اخت�Sار�Sة',
                'is_required' => false,
                'min_selections' => 0,
                'max_selections' => 3,
                'modifiers' => [
                    ['name' => 'جب�?ة إضاف�Sة', 'price' => 3, 'sort_order' => 1],
                    ['name' => '�"ح�. إضاف�S', 'price' => 8, 'sort_order' => 2],
                    ['name' => 'ص�^ص إضاف�S', 'price' => 2, 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'اخت�Sار ا�"ص�^ص',
                'is_required' => false,
                'min_selections' => 0,
                'max_selections' => 2,
                'modifiers' => [
                    ['name' => 'ص�^ص بارب�f�S�^', 'price' => 0, 'sort_order' => 1],
                    ['name' => 'ص�^ص را�?ش', 'price' => 0, 'sort_order' => 2],
                    ['name' => 'ص�^ص حار', 'price' => 0, 'sort_order' => 3],
                ],
            ],
        ];

        $productIds = DB::table('products')
            ->where('restaurant_id', $restaurant->id)
            ->orderBy('id')
            ->limit(4)
            ->pluck('id')
            ->all();

        foreach ($groups as $group) {
            $groupId = DB::table('modifier_groups')->updateOrInsert(
                [
                    'restaurant_id' => $restaurant->id,
                    'name' => $group['name'],
                ],
                [
                    'is_required' => $group['is_required'],
                    'min_selections' => $group['min_selections'],
                    'max_selections' => $group['max_selections'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            )
                ? DB::table('modifier_groups')
                    ->where('restaurant_id', $restaurant->id)
                    ->where('name', $group['name'])
                    ->value('id')
                : null;

            if (! $groupId) {
                continue;
            }

            foreach ($group['modifiers'] as $modifier) {
                DB::table('modifiers')->updateOrInsert(
                    [
                        'modifier_group_id' => $groupId,
                        'name' => $modifier['name'],
                    ],
                    [
                        'price' => $modifier['price'],
                        'sort_order' => $modifier['sort_order'],
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            foreach ($productIds as $productId) {
                DB::table('modifier_group_product')->updateOrInsert(
                    [
                        'modifier_group_id' => $groupId,
                        'product_id' => $productId,
                    ],
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedRestaurantImages(Restaurant $restaurant): void
    {
        $seed = $restaurant->slug ?? (string) $restaurant->id;
        SeederMedia::ensureSingleMedia(
            $restaurant,
            'primary-image',
            'https://images.unsplash.com/photo-1552566626-52f8b828add9?auto=format&fit=crop&w=1200&q=80',
            "restaurant-{$seed}"
        );

        $products = Product::query()
            ->where('restaurant_id', $restaurant->id)
            ->get();

        foreach ($products as $product) {
            if ($product->getFirstMedia('primary-image') !== null) {
                continue;
            }

            SeederMedia::ensureSingleMedia(
                $product,
                'primary-image',
                'https://images.unsplash.com/photo-1515003197210-e0cd71810b5f?auto=format&fit=crop&w=800&q=80',
                "restaurant-{$seed}-product-{$product->id}"
            );
        }
    }

    private function seedProductSubstitutions(Restaurant $restaurant): void
    {
        $products = DB::table('products')
            ->where('restaurant_id', $restaurant->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (count($products) < 2) {
            return;
        }

        // Create substitutions between similar products
        foreach ($products as $index => $productId) {
            // Each product can be substituted with the next product
            if (isset($products[$index + 1])) {
                DB::table('restaurant_product_substitutions')->updateOrInsert(
                    [
                        'restaurant_id' => $restaurant->id,
                        'product_id' => $productId,
                        'substitute_product_id' => $products[$index + 1],
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // Create reverse substitution
            if (isset($products[$index + 1])) {
                DB::table('restaurant_product_substitutions')->updateOrInsert(
                    [
                        'restaurant_id' => $restaurant->id,
                        'product_id' => $products[$index + 1],
                        'substitute_product_id' => $productId,
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedProductImages(Restaurant $restaurant): void
    {
        $seed = $restaurant->slug ?? (string) $restaurant->id;
        $products = Product::query()
            ->where('restaurant_id', $restaurant->id)
            ->get();

        foreach ($products as $product) {
            if ($product->getMedia('images')->isNotEmpty()) {
                continue;
            }

            $imageCount = fake()->numberBetween(2, 3);
            for ($i = 1; $i <= $imageCount; $i++) {
                $imageSeed = "restaurant-{$seed}-product-{$product->id}-img{$i}";
                $imageUrl = 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=800&q=80';

                $this->attachProductImage($product, $imageUrl, $imageSeed);
            }
        }
    }

    private function attachProductImage(Product $product, string $imageUrl, string $imageSeed): void
    {
        if (! app()->runningUnitTests()) {
            try {
                $product->addMediaFromUrl($imageUrl)->toMediaCollection('images');

                return;
            } catch (Throwable) {
                // Continue to local placeholder fallback.
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'seed-media-');
        if ($tempPath === false) {
            return;
        }

        $pngPath = $tempPath.'-'.Str::slug($imageSeed, '-').'.png';
        @unlink($tempPath);

        $decoded = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAtMB9dZYkEYAAAAASUVORK5CYII=', true);
        if (! is_string($decoded) || $decoded === '') {
            return;
        }

        $bytes = file_put_contents($pngPath, $decoded);
        if ($bytes === false || $bytes === 0) {
            @unlink($pngPath);

            return;
        }

        try {
            $product->addMedia($pngPath)
                ->usingFileName(Str::slug($imageSeed, '-').'.png')
                ->toMediaCollection('images');
        } catch (Throwable) {
            // Ignore media failures in seed data.
        } finally {
            if (is_file($pngPath)) {
                @unlink($pngPath);
            }
        }
    }
}
