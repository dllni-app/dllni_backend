<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\CategoryFactory;
use Database\Factories\OfferFactory;
use Database\Factories\OrderFactory;
use Database\Factories\ProductFactory;
use Database\Factories\RestaurantFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Modules\Resturants\Enums\DayOfWeek;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\OperatingHour;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\Restaurant;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $runSuffix = (string) now()->timestamp.'_'.bin2hex(random_bytes(4));

    $userPassword = 'QaUser1234!';
    $ownerPassword = 'QaOwner1234!';

    $nextUniquePhone = static function (): string {
        do {
            $candidate = '+9639'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        } while (User::query()->where('phone', $candidate)->exists());

        return $candidate;
    };

    $userPhone = $nextUniquePhone();
    $ownerPhone = $nextUniquePhone();

    $user = User::factory()->create([
        'name' => 'PW Restaurant User '.$runSuffix,
        'email' => "pw-rs-user-{$runSuffix}@example.test",
        'phone' => $userPhone,
        'password' => Hash::make($userPassword),
    ]);

    $owner = User::factory()->create([
        'name' => 'PW Restaurant Owner '.$runSuffix,
        'email' => "pw-rs-owner-{$runSuffix}@example.test",
        'phone' => $ownerPhone,
        'password' => Hash::make($ownerPassword),
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $wrongRole = User::factory()->create([
        'name' => 'PW Restaurant Wrong Role '.$runSuffix,
        'email' => "pw-rs-wrong-role-{$runSuffix}@example.test",
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    $otherOwner = User::factory()->create([
        'name' => 'PW Restaurant Other Owner '.$runSuffix,
        'email' => "pw-rs-other-owner-{$runSuffix}@example.test",
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    /** @var Restaurant $ownerRestaurant */
    $ownerRestaurant = RestaurantFactory::new()->create([
        'user_id' => $owner->id,
        'name' => 'PW Restaurant '.$runSuffix,
        'slug' => Str::slug('pw-restaurant-'.$runSuffix),
        'is_active' => true,
        'is_temporarily_closed' => false,
        'suspension_until' => null,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    /** @var Restaurant $otherRestaurant */
    $otherRestaurant = RestaurantFactory::new()->create([
        'user_id' => $otherOwner->id,
        'name' => 'PW Other Restaurant '.$runSuffix,
        'slug' => Str::slug('pw-other-restaurant-'.$runSuffix),
        'is_active' => true,
        'is_temporarily_closed' => false,
        'suspension_until' => null,
        'latitude' => 33.5200,
        'longitude' => 36.2800,
    ]);

    foreach (DayOfWeek::cases() as $day) {
        OperatingHour::create([
            'restaurant_id' => $ownerRestaurant->id,
            'day_of_week' => $day->value,
            'open_time' => '00:00:00',
            'close_time' => '23:59:00',
            'is_closed' => false,
        ]);

        OperatingHour::create([
            'restaurant_id' => $otherRestaurant->id,
            'day_of_week' => $day->value,
            'open_time' => '00:00:00',
            'close_time' => '23:59:00',
            'is_closed' => false,
        ]);
    }

    $primaryCategory = CategoryFactory::new()->create([
        'restaurant_id' => $ownerRestaurant->id,
        'name' => 'PW Main Dishes '.$runSuffix,
        'slug' => Str::slug('pw-main-dishes-'.$runSuffix),
    ]);

    $secondaryCategory = CategoryFactory::new()->create([
        'restaurant_id' => $ownerRestaurant->id,
        'name' => 'PW Starters '.$runSuffix,
        'slug' => Str::slug('pw-starters-'.$runSuffix),
    ]);

    $availableProduct = ProductFactory::new()->create([
        'restaurant_id' => $ownerRestaurant->id,
        'category_id' => $primaryCategory->id,
        'name' => 'PW Burger '.$runSuffix,
        'description' => 'Playwright seeded burger',
        'price' => 18.0,
        'discounted_price' => 16.0,
        'is_available' => true,
        'stock_quantity' => 50,
        'is_featured' => true,
    ]);

    $secondAvailableProduct = ProductFactory::new()->create([
        'restaurant_id' => $ownerRestaurant->id,
        'category_id' => $secondaryCategory->id,
        'name' => 'PW Fries '.$runSuffix,
        'description' => 'Playwright seeded fries',
        'price' => 8.0,
        'discounted_price' => null,
        'is_available' => true,
        'stock_quantity' => 70,
        'is_featured' => false,
    ]);

    $unavailableProduct = ProductFactory::new()->create([
        'restaurant_id' => $ownerRestaurant->id,
        'category_id' => $secondaryCategory->id,
        'name' => 'PW Hidden Item '.$runSuffix,
        'is_available' => false,
        'stock_quantity' => 0,
    ]);

    $activeOffer = OfferFactory::new()->create([
        'restaurant_id' => $ownerRestaurant->id,
        'name' => 'PW Active Offer '.$runSuffix,
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(2),
        'is_active' => true,
    ]);
    $activeOffer->products()->syncWithoutDetaching([$availableProduct->id, $secondAvailableProduct->id]);

    $promoCode = PromoCode::query()->create([
        'restaurant_id' => $ownerRestaurant->id,
        'code' => 'PWRS'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
        'discount_type' => 'fixed_amount',
        'discount_value' => 3,
        'min_order_amount' => 5,
        'usage_limit' => 100,
        'usage_count' => 0,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(7),
        'is_active' => true,
    ]);

    $orderCustomer = User::factory()->create([
        'name' => 'PW Restaurant Order Customer '.$runSuffix,
        'email' => "pw-rs-order-customer-{$runSuffix}@example.test",
    ]);

    $pendingAcceptOrder = OrderFactory::new()->create([
        'user_id' => $orderCustomer->id,
        'restaurant_id' => $ownerRestaurant->id,
        'status' => OrderStatus::Pending->value,
        'order_type' => OrderType::DineIn->value,
        'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
        'subtotal' => 20,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'service_fee' => 0,
        'total_amount' => 20,
    ]);

    OrderItem::query()->create([
        'order_id' => $pendingAcceptOrder->id,
        'product_id' => $availableProduct->id,
        'substitute_product_id' => null,
        'quantity' => 1,
        'unit_price' => 20,
        'total_price' => 20,
        'special_instructions' => null,
    ]);

    $pendingRejectOrder = OrderFactory::new()->create([
        'user_id' => $orderCustomer->id,
        'restaurant_id' => $ownerRestaurant->id,
        'status' => OrderStatus::Pending->value,
        'order_type' => OrderType::DineIn->value,
        'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
        'subtotal' => 18,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'service_fee' => 0,
        'total_amount' => 18,
    ]);

    OrderItem::query()->create([
        'order_id' => $pendingRejectOrder->id,
        'product_id' => $secondAvailableProduct->id,
        'substitute_product_id' => null,
        'quantity' => 2,
        'unit_price' => 9,
        'total_price' => 18,
        'special_instructions' => null,
    ]);

    $completedOrder = OrderFactory::new()->create([
        'user_id' => $user->id,
        'restaurant_id' => $ownerRestaurant->id,
        'status' => OrderStatus::Completed->value,
        'order_type' => OrderType::DineIn->value,
        'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
        'subtotal' => 24,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'service_fee' => 0,
        'total_amount' => 24,
    ]);

    OrderItem::query()->create([
        'order_id' => $completedOrder->id,
        'product_id' => $availableProduct->id,
        'substitute_product_id' => null,
        'quantity' => 1,
        'unit_price' => 16,
        'total_price' => 16,
        'special_instructions' => null,
    ]);

    OrderItem::query()->create([
        'order_id' => $completedOrder->id,
        'product_id' => $secondAvailableProduct->id,
        'substitute_product_id' => null,
        'quantity' => 1,
        'unit_price' => 8,
        'total_price' => 8,
        'special_instructions' => null,
    ]);

    InventoryItem::query()->create([
        'restaurant_id' => $ownerRestaurant->id,
        'name' => 'PW Inventory Item '.$runSuffix,
        'unit' => 'piece',
        'quantity' => 12,
        'minimum_limit' => 3,
        'unit_cost' => 1.75,
    ]);

    $tokenName = 'playwright-restaurant-'.$runSuffix;

    $userToken = $user->createToken($tokenName.'-user')->plainTextToken;
    $ownerToken = $owner->createToken($tokenName.'-owner')->plainTextToken;
    $wrongRoleToken = $wrongRole->createToken($tokenName.'-wrong-role')->plainTextToken;

    $payload = [
        'runId' => $runSuffix,
        'auth' => [
            'user' => [
                'phone' => $userPhone,
                'password' => $userPassword,
            ],
            'owner' => [
                'phone' => $ownerPhone,
                'password' => $ownerPassword,
            ],
        ],
        'actors' => [
            'user' => [
                'id' => $user->id,
                'token' => $userToken,
            ],
            'owner' => [
                'id' => $owner->id,
                'token' => $ownerToken,
            ],
            'wrong_role' => [
                'id' => $wrongRole->id,
                'token' => $wrongRoleToken,
            ],
        ],
        'fixtures' => [
            'restaurants' => [
                'owned' => $ownerRestaurant->id,
                'other' => $otherRestaurant->id,
            ],
            'categories' => [
                'primary' => $primaryCategory->id,
                'secondary' => $secondaryCategory->id,
            ],
            'products' => [
                'available' => $availableProduct->id,
                'secondary' => $secondAvailableProduct->id,
                'unavailable' => $unavailableProduct->id,
            ],
            'offers' => [
                'active' => $activeOffer->id,
            ],
            'promoCodes' => [
                'active' => $promoCode->id,
                'activeCode' => $promoCode->code,
                'invalidCode' => 'PWRS_INVALID_COUPON',
            ],
            'orders' => [
                'pendingAccept' => $pendingAcceptOrder->id,
                'pendingReject' => $pendingRejectOrder->id,
                'completedLatest' => $completedOrder->id,
            ],
        ],
        'generatedAt' => now()->toIso8601String(),
    ];

    echo json_encode($payload, JSON_THROW_ON_ERROR);
} catch (\Throwable $throwable) {
    fwrite(STDERR, (string) $throwable);
    exit(1);
}
