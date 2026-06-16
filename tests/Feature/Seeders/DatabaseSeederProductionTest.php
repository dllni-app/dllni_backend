<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmStore;

beforeEach(function (): void {
    config(['app.env' => 'production']);
});

afterEach(function (): void {
    config(['app.env' => 'testing']);
});

it('seeds only bootstrap config and test users in production', function (): void {
    Artisan::call('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--no-interaction' => true,
    ]);

    expect(User::where('email', 'admin@dllni.sy')->exists())->toBeTrue();
    expect(User::where('email', 'admin@admin.com')->exists())->toBeTrue();
    expect(User::where('email', 'user@dllni.sy')->exists())->toBeTrue();
    expect(User::where('email', 'user2@dllni.sy')->exists())->toBeTrue();

    expect(User::whereIn('email', [
        'cleaning.worker@dllni.sy',
        'seller@dllni.sy',
        'supermarket.seller@dllni.sy',
        'worker@dllni.sy',
    ])->exists())->toBeFalse();

    expect(Restaurant::query()->count())->toBe(0);
    expect(SmStore::query()->count())->toBe(0);
    expect(Worker::query()->count())->toBe(0);
    expect(CleaningBooking::query()->count())->toBe(0);
});
