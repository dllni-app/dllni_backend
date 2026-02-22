<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\CleaningWorkerAndSellerSeeder;
use Modules\Resturants\Models\Restaurant;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(CleaningWorkerAndSellerSeeder::class);
});

it('creates cleaning_worker role with cleaning API permissions', function (): void {
    $role = Role::where('name', 'cleaning_worker')->first();

    expect($role)->not->toBeNull();
    expect($role->permissions->pluck('name')->all())->toContain('cleaning_bookings.view');
    expect($role->permissions->pluck('name')->all())->toContain('event_bookings.view');
    expect($role->permissions->pluck('name')->all())->toContain('worker_homepage.view');
    expect($role->permissions->pluck('name')->all())->toContain('geographic_coverage.view');
});

it('creates restaurant_seller role with seller API permissions', function (): void {
    $role = Role::where('name', 'restaurant_seller')->first();

    expect($role)->not->toBeNull();
    expect($role->permissions->pluck('name')->all())->toContain('seller_restaurants.view');
    expect($role->permissions->pluck('name')->all())->toContain('seller_orders.view');
    expect($role->permissions->pluck('name')->all())->toContain('seller_products.view');
});

it('creates cleaning worker user with Worker record and cleaning_worker role', function (): void {
    $user = User::where('email', 'cleaning.worker@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Cleaning Worker');
    expect($user->phone)->toBe('+962790000001');
    expect($user->hasRole('cleaning_worker'))->toBeTrue();

    $worker = Worker::where('user_id', $user->id)->first();
    expect($worker)->not->toBeNull();
    expect($worker->first_name)->toBe('Cleaning');
    expect($worker->is_active)->toBeTrue();
});

it('creates seller user with restaurant_seller role and linked Restaurant', function (): void {
    $user = User::where('email', 'seller@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Restaurant Seller');
    expect($user->phone)->toBe('+962790000002');
    expect($user->hasRole('restaurant_seller'))->toBeTrue();

    $restaurant = Restaurant::where('user_id', $user->id)->first();
    expect($restaurant)->not->toBeNull();
    expect($restaurant->name)->toBe('Seller Restaurant');
    expect($restaurant->is_active)->toBeTrue();
});

it('is idempotent when run twice', function (): void {
    $this->seed(CleaningWorkerAndSellerSeeder::class);

    $cleaningWorkers = User::where('email', 'cleaning.worker@example.com')->count();
    $sellers = User::where('email', 'seller@example.com')->count();

    expect($cleaningWorkers)->toBe(1);
    expect($sellers)->toBe(1);
});
