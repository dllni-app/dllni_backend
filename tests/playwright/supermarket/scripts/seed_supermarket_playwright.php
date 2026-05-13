<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\MasterProduct;
use App\Models\User;
use Database\Factories\MasterProductFactory;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmStore;

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $runSuffix = (string) now()->timestamp.'_'.bin2hex(random_bytes(4));

    $user = User::factory()->create([
        'name' => 'PW Supermarket User '.$runSuffix,
        'email' => "pw-sm-user-{$runSuffix}@example.test",
    ]);

$owner = User::factory()->create([
    'name' => 'PW Supermarket Owner '.$runSuffix,
    'email' => "pw-sm-owner-{$runSuffix}@example.test",
    'module_type' => UserModuleType::SupermarketSeller->value,
]);

$wrongRole = User::factory()->create([
    'name' => 'PW Wrong Role '.$runSuffix,
    'email' => "pw-sm-wrong-role-{$runSuffix}@example.test",
    'module_type' => UserModuleType::RestaurantSeller->value,
]);

$otherOwner = User::factory()->create([
    'name' => 'PW Other Owner '.$runSuffix,
    'email' => "pw-sm-other-owner-{$runSuffix}@example.test",
    'module_type' => UserModuleType::SupermarketSeller->value,
]);

/** @var SmStore $ownerStore */
$ownerStore = SmStoreFactory::new()->create([
    'owner_user_id' => $owner->id,
    'name' => 'PW Supermarket Store '.$runSuffix,
    'is_active' => true,
]);

    $otherStore = SmStoreFactory::new()->create([
        'owner_user_id' => $otherOwner->id,
        'name' => 'PW Other Store '.$runSuffix,
        'is_active' => true,
    ]);

    DB::table('sm_carts')->updateOrInsert(
        ['user_id' => $user->id],
        [
            'store_id' => $ownerStore->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

$category = SmCategoryFactory::new()->create([
    'store_id' => $ownerStore->id,
    'name' => 'PW Category '.$runSuffix,
]);

$availableProduct = SmProductFactory::new()->create([
    'store_id' => $ownerStore->id,
    'category_id' => $category->id,
    'name' => 'PW Available Milk '.$runSuffix,
    'price' => 7.50,
    'stock_quantity' => 40,
    'low_stock_threshold' => 10,
    'is_available' => true,
]);

$similarProduct = SmProductFactory::new()->create([
    'store_id' => $ownerStore->id,
    'category_id' => $category->id,
    'name' => 'PW Available Milk 1L '.$runSuffix,
    'price' => 8.25,
    'stock_quantity' => 25,
    'is_available' => true,
]);

$lowStockProduct = SmProductFactory::new()->create([
    'store_id' => $ownerStore->id,
    'category_id' => $category->id,
    'name' => 'PW Low Stock Juice '.$runSuffix,
    'price' => 4.25,
    'stock_quantity' => 2,
    'low_stock_threshold' => 7,
    'is_available' => true,
]);

$unavailableProduct = SmProductFactory::new()->create([
    'store_id' => $ownerStore->id,
    'category_id' => $category->id,
    'name' => 'PW Unavailable Milk '.$runSuffix,
    'stock_quantity' => 0,
    'is_available' => false,
]);

$masterProduct = MasterProductFactory::new()->create([
    'name' => 'PW Master Item '.$runSuffix,
    'barcode' => 'PW'.$runSuffix,
    'is_active' => true,
]);

$otherStoreMasterProduct = MasterProductFactory::new()->create([
    'name' => 'PW Other Store Master '.$runSuffix,
    'barcode' => 'PW-OTHER-'.$runSuffix,
    'is_active' => true,
]);

SmProductFactory::new()->create([
    'store_id' => $ownerStore->id,
    'category_id' => $category->id,
    'master_product_id' => $masterProduct->id,
    'name' => 'PW Master Linked Item '.$runSuffix,
    'stock_quantity' => 20,
    'is_available' => true,
]);

$otherStoreCategory = SmCategoryFactory::new()->create([
    'store_id' => $otherStore->id,
    'name' => 'PW Other Category '.$runSuffix,
]);

SmProductFactory::new()->create([
    'store_id' => $otherStore->id,
    'category_id' => $otherStoreCategory->id,
    'master_product_id' => $otherStoreMasterProduct->id,
    'name' => 'PW Other Master Linked '.$runSuffix,
    'stock_quantity' => 11,
    'is_available' => true,
]);

$offer = SmOfferFactory::new()->create([
    'store_id' => $ownerStore->id,
    'name' => 'PW Offer '.$runSuffix,
    'is_active' => true,
    'starts_at' => now()->subHour(),
    'ends_at' => now()->addDay(),
]);

SmOfferProductFactory::new()->create([
    'offer_id' => $offer->id,
    'product_id' => $availableProduct->id,
]);

$pendingOrderForAccept = SmOrderFactory::new()->pending()->create([
    'store_id' => $ownerStore->id,
    'customer_id' => $user->id,
]);

SmOrderItemFactory::new()->create([
    'order_id' => $pendingOrderForAccept->id,
    'product_id' => $availableProduct->id,
    'quantity' => 2,
    'unit_price' => 7.50,
    'total_price' => 15.00,
    'product_name' => $availableProduct->name,
]);

$pendingOrderForReject = SmOrderFactory::new()->pending()->create([
    'store_id' => $ownerStore->id,
    'customer_id' => $user->id,
]);

$readyForPickupOrder = SmOrderFactory::new()->readyForPickup()->create([
    'store_id' => $ownerStore->id,
    'customer_id' => $user->id,
]);

$nonReadyOrder = SmOrderFactory::new()->accepted()->create([
    'store_id' => $ownerStore->id,
    'customer_id' => $user->id,
    'status' => SmOrderStatus::Accepted->value,
]);

$otherStorePendingOrder = SmOrderFactory::new()->pending()->create([
    'store_id' => $otherStore->id,
    'customer_id' => $user->id,
]);

$trackingOrder = SmOrderFactory::new()->pending()->create([
    'store_id' => $ownerStore->id,
    'customer_id' => $user->id,
]);

SmOrderItemFactory::new()->create([
    'order_id' => $trackingOrder->id,
    'product_id' => $similarProduct->id,
    'quantity' => 1,
    'unit_price' => 8.25,
    'total_price' => 8.25,
    'product_name' => $similarProduct->name,
]);

    $tokenName = 'playwright-supermarket-'.$runSuffix;

    $userToken = $user->createToken($tokenName.'-user')->plainTextToken;
    $ownerToken = $owner->createToken($tokenName.'-owner')->plainTextToken;
    $wrongRoleToken = $wrongRole->createToken($tokenName.'-wrong-role')->plainTextToken;

    $payload = [
        'runId' => $runSuffix,
        'actors' => [
            'user' => [
                'id' => $user->id,
                'token' => $userToken,
            ],
            'store_owner' => [
                'id' => $owner->id,
                'token' => $ownerToken,
            ],
            'wrong_role' => [
                'id' => $wrongRole->id,
                'token' => $wrongRoleToken,
            ],
        ],
        'fixtures' => [
            'store' => [
                'owned' => $ownerStore->id,
                'other' => $otherStore->id,
            ],
            'products' => [
                'available' => $availableProduct->id,
                'similar' => $similarProduct->id,
                'low_stock' => $lowStockProduct->id,
                'unavailable' => $unavailableProduct->id,
            ],
            'orders' => [
                'tracking' => $trackingOrder->id,
                'pending_accept' => $pendingOrderForAccept->id,
                'pending_reject' => $pendingOrderForReject->id,
                'ready_for_pickup' => $readyForPickupOrder->id,
                'non_ready' => $nonReadyOrder->id,
                'other_store_pending' => $otherStorePendingOrder->id,
            ],
            'master_products' => [
                'primary' => $masterProduct->id,
                'other_store_only' => $otherStoreMasterProduct->id,
            ],
        ],
        'generatedAt' => now()->toIso8601String(),
    ];

    echo json_encode($payload, JSON_THROW_ON_ERROR);
} catch (\Throwable $throwable) {
    fwrite(STDERR, (string) $throwable);
    exit(1);
}
