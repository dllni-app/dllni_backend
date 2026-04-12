<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AvailabilityType;
use App\Enums\UserModuleType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkerZone;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\PriceRange;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\OperatingHour;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantCustomerReview;
use Modules\Supermarket\Models\SmStore;

final class CleaningWorkerAndSellerSeeder extends Seeder
{
    private const string CleaningWorkerEmail = 'cleaning.worker@dllni.sy';

    private const string CleaningWorkerPhone = '+963944100001';

    private const string RestaurantSellerEmail = 'seller@dllni.sy';

    private const string RestaurantSellerPhone = '+963944100002';

    private const string SupermarketSellerEmail = 'supermarket.seller@dllni.sy';

    private const string SupermarketSellerPhone = '+963944100003';

    private const string Password = 'password';

    public function run(): void
    {
        $this->seedCleaningWorkerUser();
        $this->seedRestaurantSellerUser();
        $this->seedSupermarketSellerUser();
    }

    private function seedCleaningWorkerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::CleaningWorkerEmail],
            [
                'name' => 'Cleaning Worker',
                'phone' => self::CleaningWorkerPhone,
                'module_type' => UserModuleType::CleaningWorker,
                'password' => bcrypt(self::Password),
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'phone' => self::CleaningWorkerPhone,
            'module_type' => UserModuleType::CleaningWorker,
            'phone_verified_at' => now(),
        ])->save();

        $worker = Worker::firstOrCreate(
            ['user_id' => $user->id],
            [
                'first_name' => 'Cleaning',
                'bio' => 'Cleaning worker for API testing.',
                'average_rating' => 4.5,
                'total_completed_jobs' => 100,
                'trust_score' => 90,
                'acceptance_rate' => 95.0,
                'cancellation_rate' => 1.0,
                'open_disputes_count' => 0,
                'is_active' => true,
                'is_suspended' => false,
                'home_address' => 'ح�"ب - ا�"ح�.دا�?�Sة - شارع ا�"�,دس',
                'home_latitude' => 36.1795,
                'home_longitude' => 37.1082,
                'default_working_hours' => [
                    'sunday' => ['available' => false, 'data' => []],
                    'monday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'tuesday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'wednesday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'thursday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'friday' => ['available' => true, 'data' => [['09:00' => '18:00']]],
                    'saturday' => ['available' => true, 'data' => [['10:00' => '16:00']]],
                ],
            ]
        );

        WorkerZone::firstOrCreate(
            ['worker_id' => $worker->id, 'name' => 'ح�"ب - �?طا�, ا�"ح�.دا�?�Sة'],
            [
                'polygon' => [
                    ['lat' => 36.1670, 'lng' => 37.0950],
                    ['lat' => 36.1930, 'lng' => 37.0950],
                    ['lat' => 36.1930, 'lng' => 37.1230],
                    ['lat' => 36.1670, 'lng' => 37.1230],
                ],
                'is_active' => true,
            ]
        );

        for ($i = 0; $i < 7; $i++) {
            WorkerAvailability::firstOrCreate(
                [
                    'worker_id' => $worker->id,
                    'availability_date' => now()->addDays($i),
                ],
                [
                    'availability_type' => AvailabilityType::Available->value,
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                ]
            );
        }

        SeederMedia::ensureSingleMedia(
            $worker,
            'avatar',
            'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=512&q=80',
            "worker-{$worker->id}-avatar"
        );

        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            'https://images.unsplash.com/photo-1544005313-94ddf0286df2?auto=format&fit=crop&w=600&q=80',
            "cleaning-worker-user-{$user->id}-primary"
        );
    }

    private function seedRestaurantSellerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::RestaurantSellerEmail],
            [
                'name' => 'Restaurant Seller',
                'phone' => self::RestaurantSellerPhone,
                'module_type' => UserModuleType::RestaurantSeller,
                'password' => bcrypt(self::Password),
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'phone' => self::RestaurantSellerPhone,
            'module_type' => UserModuleType::RestaurantSeller,
            'phone_verified_at' => now(),
        ])->save();

        $slug = 'seller-restaurant-' . mb_substr(hash('sha256', (string) $user->id), 0, 8);
        $restaurant = Restaurant::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'Seller Restaurant',
                'slug' => $slug,
                'description' => 'Restaurant owned by seller user for API testing.',
                'address' => 'ح�"ب - ا�"فر�,ا�? - شارع ا�"�,صر ا�"ب�"د�S',
                'latitude' => 36.2021,
                'longitude' => 37.1343,
                'phone' => '+963 21 555 0000',
                'email' => 'seller@restaurant.dllni.sy',
                'average_rating' => 4.0,
                'total_reviews' => 0,
                'estimated_preparation_time' => 20,
                'minimum_order_amount' => 10.0,
                'price_range' => PriceRange::Medium->value,
                'reputation_score' => 85,
                'visibility_score' => 100,
                'is_active' => true,
                'is_featured' => false,
            ]
        );

        $restaurant->forceFill([
            'name' => 'Seller Restaurant',
            'slug' => $slug,
            'description' => '�.طع�. ح�"ب�S �.ت�^سط�S �S�,د�. ا�"�.شا�^�S ا�"طازجة �^ا�"أ�f�"ات ا�"شر�,�Sة �^ا�"عر�^ض ا�"�S�^�.�Sة.',
            'address' => 'ح�"ب - ح�S ا�"فر�,ا�? - شارع عبد ا�"�,ادر ا�"صا�"ح',
            'city' => 'ح�"ب',
            'district' => 'ا�"فر�,ا�?',
            'location_details' => '�,رب د�^ار ا�"صخرة - ا�"طاب�, ا�"أرض�S',
            'latitude' => 36.2021,
            'longitude' => 37.1343,
            'phone' => '+963 21 555 0000',
            'whatsapp_number' => self::RestaurantSellerPhone,
            'email' => 'seller@restaurant.dllni.sy',
            'instagram_username' => 'sellerrestaurantaleppo',
            'facebook_page_name' => '�.طع�. ا�"بائع - ح�"ب',
            'average_rating' => 4.4,
            'total_reviews' => 128,
            'estimated_preparation_time' => 25,
            'minimum_order_amount' => 8.50,
            'price_range' => PriceRange::Medium->value,
            'reputation_score' => 92,
            'warning_count' => 0,
            'visibility_score' => 100,
            'manual_visibility_override' => true,
            'is_active' => true,
            'is_featured' => true,
            'is_temporarily_closed' => false,
        ])->save();

        // Add operating hours
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            OperatingHour::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'day_of_week' => $day],
                [
                    'open_time' => '10:00',
                    'close_time' => '23:00',
                    'is_closed' => false,
                ]
            );
        }

        // Ensure cuisine types exist
        $cuisineTypes = [
            ['name' => 'إ�Sطا�"�S', 'slug' => 'italian'],
            ['name' => '�.ت�^سط�S', 'slug' => 'mediterranean'],
        ];

        $cuisineIds = [];
        foreach ($cuisineTypes as $type) {
            $cuisine = CuisineType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
            $cuisineIds[] = $cuisine->id;
        }

        // Attach cuisine types to restaurant
        if ($cuisineIds) {
            $restaurant->cuisineTypes()->sync($cuisineIds);
        }

        // Add categories and products
        $categories = [
            [
                'name' => 'ا�"أطبا�, ا�"رئ�Sس�Sة',
                'products' => [
                    [
                        'name' => 'دجاج �.ش�^�S ع�"�? ا�"فح�.',
                        'description' => '�?صف دجاجة �.ش�^�Sة ع�"�? ا�"فح�. �.ع ص�^ص ا�"ث�^�. �^شرائح ا�"بطاطا.',
                        'price' => 12.99,
                        'discounted_price' => 11.49,
                        'stock_quantity' => 60,
                        'low_stock_threshold' => 8,
                        'preparation_time' => 20,
                    ],
                    [
                        'name' => 'ست�S�f �"ح�. ب�,ر�S',
                        'description' => '�,طعة ست�S�f طر�Sة �.ع ص�^ص ا�"فطر �^خضار �.�^س�.�Sة.',
                        'price' => 15.99,
                        'discounted_price' => 14.49,
                        'stock_quantity' => 35,
                        'low_stock_threshold' => 5,
                        'preparation_time' => 25,
                    ],
                    [
                        'name' => 'باستا �fارب�^�?ارا',
                        'description' => 'باستا �fر�S�.�Sة �.ع ب�S�f�^�? ب�,ر�S �^جب�? بار�.�Sزا�? �^ف�"ف�" أس�^د.',
                        'price' => 11.99,
                        'discounted_price' => null,
                        'stock_quantity' => 42,
                        'low_stock_threshold' => 6,
                        'preparation_time' => 18,
                    ],
                ],
            ],
            [
                'name' => 'ا�"�.�,ب�"ات',
                'products' => [
                    [
                        'name' => 'س�"طة س�Sزر',
                        'description' => 'خس ر�^�.ا�?�S �.ع بار�.�Sزا�? �^�,طع خبز �.ح�.صة �^ص�^ص س�Sزر خاص.',
                        'price' => 8.99,
                        'discounted_price' => 7.99,
                        'stock_quantity' => 50,
                        'low_stock_threshold' => 7,
                        'preparation_time' => 10,
                    ],
                    [
                        'name' => 'خبز با�"ث�^�.',
                        'description' => 'شرائح خبز باغ�Sت �.ح�.صة بزبدة ا�"ث�^�. �^ا�"أعشاب.',
                        'price' => 5.99,
                        'discounted_price' => null,
                        'stock_quantity' => 70,
                        'low_stock_threshold' => 10,
                        'preparation_time' => 8,
                    ],
                    [
                        'name' => '�fا�"�S�.ار�S �.�,ر�.ش',
                        'description' => 'ح�"�,ات �fا�"�S�.ار�S �.�,�"�Sة ت�,د�. �.ع ص�^ص ا�"�"�S�.�^�?.',
                        'price' => 9.99,
                        'discounted_price' => 8.99,
                        'stock_quantity' => 28,
                        'low_stock_threshold' => 6,
                        'preparation_time' => 14,
                    ],
                ],
            ],
            [
                'name' => 'ا�"ح�"�^�Sات',
                'products' => [
                    [
                        'name' => '�f�S�f ش�^�f�^�"اتة',
                        'description' => '�f�S�f إسف�?ج�S با�"ش�^�f�^�"اتة �.ع طب�,ات جا�?اش غ�?�S.',
                        'price' => 7.99,
                        'discounted_price' => 6.99,
                        'stock_quantity' => 24,
                        'low_stock_threshold' => 4,
                        'preparation_time' => 7,
                    ],
                    [
                        'name' => 'ت�Sرا�.�Sس�^',
                        'description' => 'ح�"�^�? إ�Sطا�"�Sة �f�"اس�S�f�Sة ب�?�f�?ة ا�"�,�?�^ة.',
                        'price' => 8.99,
                        'discounted_price' => null,
                        'stock_quantity' => 22,
                        'low_stock_threshold' => 4,
                        'preparation_time' => 7,
                    ],
                    [
                        'name' => 'آ�Sس �fر�S�. فا�?�S�"ا',
                        'description' => 'س�f�^ب فا�?�S�"ا �.ع ص�^ص �fرا�.�S�".',
                        'price' => 5.50,
                        'discounted_price' => 4.99,
                        'stock_quantity' => 40,
                        'low_stock_threshold' => 8,
                        'preparation_time' => 5,
                    ],
                ],
            ],
        ];

        foreach ($categories as $i => $catData) {
            $category = Category::firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'name' => $catData['name']],
                [
                    'slug' => str()->slug($catData['name']),
                    'sort_order' => $i + 1,
                ]
            );

            $category->forceFill([
                'slug' => str()->slug($catData['name']),
                'sort_order' => $i + 1,
            ])->save();

            SeederMedia::ensureSingleMedia(
                $category,
                'category-image',
                'https://images.unsplash.com/photo-1559339352-11d035aa65de?auto=format&fit=crop&w=1000&q=80',
                "restaurant-{$slug}-category-{$category->id}"
            );

            foreach ($catData['products'] as $j => $productData) {
                $product = Product::firstOrCreate(
                    [
                        'restaurant_id' => $restaurant->id,
                        'category_id' => $category->id,
                        'name' => $productData['name'],
                    ],
                    [
                        'description' => $productData['description'],
                        'price' => $productData['price'],
                        'discounted_price' => $productData['discounted_price'],
                        'is_available' => true,
                        'unavailable_until' => null,
                        'availability_note' => '�.تاح �S�^�.�Sا حسب ت�^فر ا�"�f�.�Sة.',
                        'stock_quantity' => $productData['stock_quantity'],
                        'low_stock_threshold' => $productData['low_stock_threshold'],
                        'preparation_time' => $productData['preparation_time'],
                        'is_featured' => $j === 0,
                    ]
                );

                $product->forceFill([
                    'description' => $productData['description'],
                    'price' => $productData['price'],
                    'discounted_price' => $productData['discounted_price'],
                    'is_available' => true,
                    'unavailable_until' => null,
                    'availability_note' => '�.تاح �S�^�.�Sا حسب ت�^فر ا�"�f�.�Sة.',
                    'stock_quantity' => $productData['stock_quantity'],
                    'low_stock_threshold' => $productData['low_stock_threshold'],
                    'preparation_time' => $productData['preparation_time'],
                    'is_featured' => $j === 0,
                ])->save();

                // Add product image
                SeederMedia::ensureSingleMedia(
                    $product,
                    'primary-image',
                    'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=800&q=80',
                    "restaurant-{$slug}-product-{$product->id}"
                );
            }
        }

        // Add restaurant primary image
        SeederMedia::ensureSingleMedia(
            $restaurant,
            'primary-image',
            'https://images.unsplash.com/photo-1552566626-52f8b828add9?auto=format&fit=crop&w=1200&q=80',
            "restaurant-{$slug}"
        );

        SeederMedia::ensureSingleMedia(
            $restaurant,
            'banner-image',
            'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=1600&q=80',
            "restaurant-{$slug}-banner"
        );

        $this->seedRestaurantSellerCommercialData($restaurant);
    }

    private function seedRestaurantSellerCommercialData(Restaurant $restaurant): void
    {
        $productsByName = $restaurant->products()->get()->keyBy('name');

        if ($productsByName->isEmpty()) {
            return;
        }

        $activeOffer = Offer::updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'name' => 'عرض �.شا�^�S أ�Sا�. ا�"أسب�^ع',
            ],
            [
                'description' => 'خص�. ع�"�? أطبا�, رئ�Sس�Sة �^�.�,ب�"ات �.ختارة �.�? ا�"أحد إ�"�? ا�"خ�.�Sس.',
                'discount_type' => DiscountType::Percentage->value,
                'discount_value' => 15,
                'starts_at' => now()->subDays(5),
                'ends_at' => now()->addDays(20),
                'is_active' => true,
            ]
        );

        $dessertOffer = Offer::updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'name' => 'عرض ا�"ح�"�^�Sات',
            ],
            [
                'description' => 'خص�. ثابت ع�"�? أص�?اف ح�"�^�Sات �.ختارة.',
                'discount_type' => DiscountType::FixedAmount->value,
                'discount_value' => 1.50,
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addDays(10),
                'is_active' => true,
            ]
        );

        $activeOfferProductIds = collect([
            $productsByName->get('دجاج �.ش�^�S ع�"�? ا�"فح�.')?->id,
            $productsByName->get('ست�S�f �"ح�. ب�,ر�S')?->id,
            $productsByName->get('�fا�"�S�.ار�S �.�,ر�.ش')?->id,
        ])->filter()->values()->all();

        if ($activeOfferProductIds !== []) {
            $activeOffer->products()->syncWithoutDetaching($activeOfferProductIds);
        }

        $dessertOfferProductIds = collect([
            $productsByName->get('�f�S�f ش�^�f�^�"اتة')?->id,
            $productsByName->get('ت�Sرا�.�Sس�^')?->id,
            $productsByName->get('آ�Sس �fر�S�. فا�?�S�"ا')?->id,
        ])->filter()->values()->all();

        if ($dessertOfferProductIds !== []) {
            $dessertOffer->products()->syncWithoutDetaching($dessertOfferProductIds);
        }

        $promoCodeTen = PromoCode::updateOrCreate(
            ['code' => 'SELLER10-' . $restaurant->id],
            [
                'restaurant_id' => $restaurant->id,
                'discount_type' => DiscountType::Percentage->value,
                'discount_value' => 10,
                'min_order_amount' => 15,
                'usage_limit' => 500,
                'usage_count' => 74,
                'starts_at' => now()->subDays(30),
                'ends_at' => now()->addDays(60),
                'is_active' => true,
            ]
        );

        $promoCodeFive = PromoCode::updateOrCreate(
            ['code' => 'FAMILY5-' . $restaurant->id],
            [
                'restaurant_id' => $restaurant->id,
                'discount_type' => DiscountType::FixedAmount->value,
                'discount_value' => 5,
                'min_order_amount' => 30,
                'usage_limit' => 200,
                'usage_count' => 41,
                'starts_at' => now()->subDays(14),
                'ends_at' => now()->addDays(45),
                'is_active' => true,
            ]
        );

        $customers = [
            [
                'email' => 'omar.customer@dllni.sy',
                'name' => 'ع�.ر خا�"د',
                'phone' => '+963944110001',
            ],
            [
                'email' => 'lina.customer@dllni.sy',
                'name' => '�"�S�?ا �?اصر',
                'phone' => '+963944110002',
            ],
            [
                'email' => 'ahmad.customer@dllni.sy',
                'name' => 'أح�.د �Sاس�S�?',
                'phone' => '+963944110003',
            ],
        ];

        $customersByEmail = collect($customers)->mapWithKeys(function (array $customerData): array {
            $customer = User::firstOrCreate(
                ['email' => $customerData['email']],
                [
                    'name' => $customerData['name'],
                    'phone' => $customerData['phone'],
                    'password' => bcrypt(self::Password),
                    'email_verified_at' => now(),
                ]
            );

            $customer->forceFill([
                'name' => $customerData['name'],
                'phone' => $customerData['phone'],
                'phone_verified_at' => now(),
            ])->save();

            return [$customer->email => $customer];
        });

        $orders = [
            [
                'order_number' => 'SR-' . $restaurant->id . '-1001',
                'customer_email' => 'omar.customer@dllni.sy',
                'status' => OrderStatus::Completed->value,
                'order_type' => OrderType::Delivery->value,
                'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
                'promo_code_id' => $promoCodeTen->id,
                'subtotal' => 25.98,
                'discount_amount' => 2.60,
                'tax_amount' => 1.75,
                'service_fee' => 0.75,
                'total_amount' => 25.88,
                'accepted_at' => now()->subDays(4)->subMinutes(45),
                'preparing_at' => now()->subDays(4)->subMinutes(35),
                'completed_at' => now()->subDays(4)->subMinutes(10),
                'estimated_preparation_minutes' => 28,
                'kitchen_notes' => 'إضافة ص�^ص ث�^�. جا�?ب�S.',
                'special_instructions' => 'ا�"اتصا�" ع�?د ا�"�^ص�^�".',
                'items' => [
                    ['name' => 'دجاج �.ش�^�S ع�"�? ا�"فح�.', 'quantity' => 1],
                    ['name' => 'س�"طة س�Sزر', 'quantity' => 1],
                ],
                'review' => [
                    'rating' => 5,
                    'comment' => 'ا�"أ�f�" طازج �^ا�"ت�^ص�S�" سر�Sع جدا �^ا�"تغ�"�Sف �.�.تاز.',
                ],
            ],
            [
                'order_number' => 'SR-' . $restaurant->id . '-1002',
                'customer_email' => 'lina.customer@dllni.sy',
                'status' => OrderStatus::Completed->value,
                'order_type' => OrderType::Pickup->value,
                'pickup_mode' => RestaurantPickupMode::ScheduledPickup->value,
                'promo_code_id' => $promoCodeFive->id,
                'subtotal' => 34.97,
                'discount_amount' => 5.00,
                'tax_amount' => 2.10,
                'service_fee' => 0.50,
                'total_amount' => 32.57,
                'accepted_at' => now()->subDays(3)->subMinutes(70),
                'preparing_at' => now()->subDays(3)->subMinutes(55),
                'completed_at' => now()->subDays(3)->subMinutes(20),
                'estimated_preparation_minutes' => 35,
                'kitchen_notes' => 'بد�^�? بص�" ف�S ا�"باستا.',
                'special_instructions' => 'ا�"است�"ا�. �.�? ا�"�fا�^�?تر ا�"أ�.ا�.�S.',
                'items' => [
                    ['name' => 'باستا �fارب�^�?ارا', 'quantity' => 2],
                    ['name' => 'خبز با�"ث�^�.', 'quantity' => 1],
                ],
                'review' => [
                    'rating' => 4,
                    'comment' => 'ا�"طع�. �.�.تاز �^ا�"�f�.�Sة ج�Sدة �^ا�"است�"ا�. �fا�? �.�?ظ�..',
                ],
            ],
            [
                'order_number' => 'SR-' . $restaurant->id . '-1003',
                'customer_email' => 'ahmad.customer@dllni.sy',
                'status' => OrderStatus::Preparing->value,
                'order_type' => OrderType::Delivery->value,
                'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
                'promo_code_id' => null,
                'subtotal' => 21.98,
                'discount_amount' => 0.00,
                'tax_amount' => 1.55,
                'service_fee' => 0.75,
                'total_amount' => 24.28,
                'accepted_at' => now()->subHours(2),
                'preparing_at' => now()->subMinutes(95),
                'completed_at' => null,
                'estimated_preparation_minutes' => 30,
                'kitchen_notes' => 'ا�"تر�f�Sز ع�"�? ا�"ص�^ص ا�"حار.',
                'special_instructions' => '�,رع ا�"جرس �.رة �^احدة.',
                'items' => [
                    ['name' => 'ست�S�f �"ح�. ب�,ر�S', 'quantity' => 1],
                    ['name' => '�fا�"�S�.ار�S �.�,ر�.ش', 'quantity' => 1],
                ],
                'review' => null,
            ],
            [
                'order_number' => 'SR-' . $restaurant->id . '-1004',
                'customer_email' => 'omar.customer@dllni.sy',
                'status' => OrderStatus::Pending->value,
                'order_type' => OrderType::Pickup->value,
                'pickup_mode' => RestaurantPickupMode::ScheduledPickup->value,
                'promo_code_id' => null,
                'subtotal' => 13.49,
                'discount_amount' => 0.00,
                'tax_amount' => 0.95,
                'service_fee' => 0.30,
                'total_amount' => 14.74,
                'accepted_at' => null,
                'preparing_at' => null,
                'completed_at' => null,
                'estimated_preparation_minutes' => null,
                'kitchen_notes' => null,
                'special_instructions' => '�Sرج�? إضافة أد�^ات طعا�..',
                'items' => [
                    ['name' => '�f�S�f ش�^�f�^�"اتة', 'quantity' => 1],
                    ['name' => 'آ�Sس �fر�S�. فا�?�S�"ا', 'quantity' => 1],
                ],
                'review' => null,
            ],
        ];

        foreach ($orders as $orderData) {
            $customer = $customersByEmail->get($orderData['customer_email']);

            if (! $customer instanceof User) {
                continue;
            }

            $order = Order::updateOrCreate(
                ['order_number' => $orderData['order_number']],
                [
                    'user_id' => $customer->id,
                    'restaurant_id' => $restaurant->id,
                    'promo_code_id' => $orderData['promo_code_id'],
                    'status' => $orderData['status'],
                    'order_type' => $orderData['order_type'],
                    'pickup_mode' => $orderData['pickup_mode'],
                    'pickup_scheduled_for' => $orderData['pickup_mode'] === RestaurantPickupMode::ScheduledPickup->value
                        ? now()->addHour()
                        : null,
                    'subtotal' => $orderData['subtotal'],
                    'discount_amount' => $orderData['discount_amount'],
                    'tax_amount' => $orderData['tax_amount'],
                    'service_fee' => $orderData['service_fee'],
                    'total_amount' => $orderData['total_amount'],
                    'special_instructions' => $orderData['special_instructions'],
                    'accepted_at' => $orderData['accepted_at'],
                    'estimated_preparation_minutes' => $orderData['estimated_preparation_minutes'],
                    'kitchen_notes' => $orderData['kitchen_notes'],
                    'preparing_at' => $orderData['preparing_at'],
                    'completed_at' => $orderData['completed_at'],
                ]
            );

            foreach ($orderData['items'] as $itemData) {
                $product = $productsByName->get($itemData['name']);

                if (! $product instanceof Product) {
                    continue;
                }

                $quantity = (int) $itemData['quantity'];
                $unitPrice = (float) $product->price;

                OrderItem::updateOrCreate(
                    [
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                    ],
                    [
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $quantity * $unitPrice,
                        'special_instructions' => null,
                    ]
                );
            }

            $this->seedOrderStatusLogs($order);

            if (is_array($orderData['review']) && $order->status === OrderStatus::Completed) {
                RestaurantCustomerReview::updateOrCreate(
                    [
                        'restaurant_id' => $restaurant->id,
                        'order_id' => $order->id,
                        'customer_id' => $customer->id,
                    ],
                    [
                        'created_by_user_id' => $customer->id,
                        'rating' => $orderData['review']['rating'],
                        'comment' => $orderData['review']['comment'],
                    ]
                );
            }
        }

        $this->syncRestaurantRatingSummary($restaurant);
    }

    private function seedOrderStatusLogs(Order $order): void
    {
        OrderStatusLog::firstOrCreate(
            [
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Pending->value,
            ],
            ['note' => 'ت�. إ�?شاء ا�"ط�"ب �.�? ا�"ع�.�S�".']
        );

        if (in_array($order->status->value, [OrderStatus::Accepted->value, OrderStatus::Preparing->value, OrderStatus::ReadyForPickup->value, OrderStatus::PickedUp->value, OrderStatus::Completed->value], true)) {
            OrderStatusLog::firstOrCreate(
                [
                    'order_id' => $order->id,
                    'from_status' => OrderStatus::Pending->value,
                    'to_status' => OrderStatus::Accepted->value,
                ],
                ['note' => 'ت�. �,ب�^�" ا�"ط�"ب �.�? ا�"�.طع�..']
            );
        }

        if (in_array($order->status->value, [OrderStatus::Preparing->value, OrderStatus::ReadyForPickup->value, OrderStatus::PickedUp->value, OrderStatus::Completed->value], true)) {
            OrderStatusLog::firstOrCreate(
                [
                    'order_id' => $order->id,
                    'from_status' => OrderStatus::Accepted->value,
                    'to_status' => OrderStatus::Preparing->value,
                ],
                ['note' => 'بدأ ا�"�.طبخ بتحض�Sر ا�"ط�"ب.']
            );
        }

        if (in_array($order->status->value, [OrderStatus::Completed->value], true)) {
            OrderStatusLog::firstOrCreate(
                [
                    'order_id' => $order->id,
                    'from_status' => OrderStatus::Preparing->value,
                    'to_status' => OrderStatus::Completed->value,
                ],
                ['note' => 'ت�. تس�"�S�. ا�"ط�"ب ب�?جاح.']
            );
        }
    }

    private function syncRestaurantRatingSummary(Restaurant $restaurant): void
    {
        $ratingSummary = RestaurantCustomerReview::query()
            ->where('restaurant_id', $restaurant->id)
            ->selectRaw('COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as reviews_count')
            ->first();

        if ($ratingSummary === null) {
            return;
        }

        $restaurant->forceFill([
            'average_rating' => (float) $ratingSummary->avg_rating,
            'total_reviews' => (int) $ratingSummary->reviews_count,
        ])->save();
    }

    private function seedSupermarketSellerUser(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::SupermarketSellerEmail],
            [
                'name' => 'Supermarket Seller',
                'phone' => self::SupermarketSellerPhone,
                'module_type' => UserModuleType::SupermarketSeller,
                'password' => bcrypt(self::Password),
                'email_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'phone' => self::SupermarketSellerPhone,
            'module_type' => UserModuleType::SupermarketSeller,
            'phone_verified_at' => now(),
        ])->save();

        if (SmStore::where('owner_user_id', $user->id)->exists()) {
            return;
        }

        SmStore::create([
            'owner_user_id' => $user->id,
            'name' => 'Seller Supermarket',
            'slug' => 'seller-supermarket-' . mb_substr(hash('sha256', (string) $user->id), 0, 8),
            'description' => 'Supermarket owned by seller user for API testing.',
            'address' => 'ح�"ب - ا�"سر�Sا�? ا�"جد�Sدة - شارع تشر�S�?',
            'city' => 'ح�"ب',
            'neighborhood' => 'ا�"سر�Sا�? ا�"جد�Sدة',
            'latitude' => 36.2168,
            'longitude' => 37.1317,
            'phone' => '+963 21 555 0001',
            'email' => 'seller@supermarket.dllni.sy',
            'average_rating' => 4.0,
            'total_reviews' => 0,
            'trust_score' => 85,
            'warning_count' => 0,
            'is_active' => true,
            'is_featured' => false,
        ]);
    }
}
