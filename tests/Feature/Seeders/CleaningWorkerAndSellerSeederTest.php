<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\CleaningWorkerAndSellerSeeder;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmStore;

beforeEach(function (): void {
    $this->seed(CleaningWorkerAndSellerSeeder::class);
});

it('creates cleaning worker user with Worker record and module type', function (): void {
    $user = User::where('email', 'cleaning.worker@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Cleaning Worker');
    expect($user->phone)->toBe('+963944100001');
    expect($user->module_type)->toBe(UserModuleType::CleaningWorker);

    $worker = Worker::where('user_id', $user->id)->first();
    expect($worker)->not->toBeNull();
    expect($worker->first_name)->toBe('Cleaning');
    expect($worker->is_active)->toBeTrue();
});

it('creates restaurant seller user with linked Restaurant and module type', function (): void {
    $user = User::where('email', 'seller@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Restaurant Seller');
    expect($user->phone)->toBe('+963944100002');
    expect($user->module_type)->toBe(UserModuleType::RestaurantSeller);

    $restaurant = Restaurant::where('user_id', $user->id)->first();
    expect($restaurant)->not->toBeNull();
    expect($restaurant->name)->toBe('Seller Restaurant');
    expect($restaurant->is_active)->toBeTrue();
});

it('creates supermarket seller user with linked store and module type', function (): void {
    $user = User::where('email', 'supermarket.seller@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Supermarket Seller');
    expect($user->phone)->toBe('+963944100003');
    expect($user->module_type)->toBe(UserModuleType::SupermarketSeller);

    $store = SmStore::where('owner_user_id', $user->id)->first();
    expect($store)->not->toBeNull();
    expect($store->name)->toBe('Seller Supermarket');
    expect($store->is_active)->toBeTrue();
});

it('is idempotent when run twice', function (): void {
    $this->seed(CleaningWorkerAndSellerSeeder::class);

    expect(User::where('email', 'cleaning.worker@example.com')->count())->toBe(1);
    expect(User::where('email', 'seller@example.com')->count())->toBe(1);
    expect(User::where('email', 'supermarket.seller@example.com')->count())->toBe(1);
});
