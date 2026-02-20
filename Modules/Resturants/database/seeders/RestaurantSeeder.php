<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\PriceRange;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

final class RestaurantSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'restaurant.owner@example.com'],
            [
                'name' => 'Restaurant Owner',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $restaurants = [
            [
                'name' => 'La Piazza Italian Kitchen',
                'slug' => 'la-piazza-italian',
                'description' => 'Authentic Italian cuisine with wood-fired pizzas and homemade pasta. Family recipes passed down for generations.',
                'address' => '15 Restaurant Row, Downtown',
                'latitude' => 31.963158,
                'longitude' => 35.930359,
                'phone' => '+962 6 555 1234',
                'email' => 'info@lapiazza.example.com',
                'price_range' => PriceRange::Medium->value,
                'minimum_order_amount' => 15.00,
                'estimated_preparation_time' => 25,
                'is_featured' => true,
            ],
            [
                'name' => 'Golden Dragon Asian Bistro',
                'slug' => 'golden-dragon-asian',
                'description' => 'Modern Asian fusion with sushi, dim sum, and wok dishes. Fresh ingredients daily.',
                'address' => '88 Food Street, West District',
                'latitude' => 31.970000,
                'longitude' => 35.935000,
                'phone' => '+962 6 555 5678',
                'email' => 'contact@goldendragon.example.com',
                'price_range' => PriceRange::High->value,
                'minimum_order_amount' => 20.00,
                'estimated_preparation_time' => 30,
                'is_featured' => true,
            ],
            [
                'name' => 'Burger Haven',
                'slug' => 'burger-haven',
                'description' => 'Gourmet burgers and hand-cut fries. Quick service for pickup and dine-in.',
                'address' => '42 Fast Food Lane',
                'latitude' => 31.955000,
                'longitude' => 35.925000,
                'phone' => '+962 6 555 9012',
                'email' => 'hello@burgerhaven.example.com',
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
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                    'price_range' => $data['price_range'],
                    'minimum_order_amount' => $data['minimum_order_amount'],
                    'estimated_preparation_time' => $data['estimated_preparation_time'],
                    'average_rating' => fake()->randomFloat(2, 4.0, 4.9),
                    'total_reviews' => fake()->numberBetween(50, 500),
                    'reputation_score' => fake()->numberBetween(85, 100),
                    'visibility_score' => 100,
                    'is_active' => true,
                    'is_featured' => $data['is_featured'],
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
                ['name' => 'Starters', 'slug' => 'starters'],
                ['name' => 'Main Course', 'slug' => 'main-course'],
                ['name' => 'Desserts', 'slug' => 'desserts'],
                ['name' => 'Drinks', 'slug' => 'drinks'],
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

            $this->seedSampleOrders($restaurant, $owner, $cancellationPolicy);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProductsForCategory(int $restaurantId, int $categoryId, string $categorySlug): array
    {
        $baseProducts = match ($categorySlug) {
            'starters' => [
                ['name' => 'Caesar Salad', 'price' => 8.99],
                ['name' => 'Soup of the Day', 'price' => 6.50],
                ['name' => 'Garlic Bread', 'price' => 5.99],
                ['name' => 'Bruschetta', 'price' => 7.50],
            ],
            'main-course' => [
                ['name' => 'Grilled Chicken', 'price' => 14.99],
                ['name' => 'Beef Burger', 'price' => 12.99],
                ['name' => 'Pasta Carbonara', 'price' => 13.50],
                ['name' => 'Fish & Chips', 'price' => 15.99],
            ],
            'desserts' => [
                ['name' => 'Chocolate Cake', 'price' => 7.99],
                ['name' => 'Ice Cream', 'price' => 5.50],
                ['name' => 'Tiramisu', 'price' => 8.99],
            ],
            'drinks' => [
                ['name' => 'Fresh Juice', 'price' => 4.50],
                ['name' => 'Soft Drink', 'price' => 2.99],
                ['name' => 'Coffee', 'price' => 3.50],
                ['name' => 'Water', 'price' => 1.50],
            ],
            default => [],
        };

        $products = [];
        foreach ($baseProducts as $p) {
            $products[] = [
                'restaurant_id' => $restaurantId,
                'category_id' => $categoryId,
                'master_product_id' => null,
                'name' => $p['name'],
                'slug' => Str::slug($p['name']).'-'.fake()->unique()->numberBetween(1000, 9999),
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
            'italian' => ['name' => 'Italian', 'slug' => 'italian'],
            'asian' => ['name' => 'Asian', 'slug' => 'asian'],
            'american' => ['name' => 'American', 'slug' => 'american'],
            'mediterranean' => ['name' => 'Mediterranean', 'slug' => 'mediterranean'],
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
                'name' => 'Restaurant Customer',
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
}
