<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\UserAppScenarioSeeder;
use Database\Seeders\VerifiedUserSeeder;
use Illuminate\Support\Facades\Artisan;

it('seeds complete app-user profile data for user endpoints', function (): void {
    Artisan::call('db:seed', ['--class' => VerifiedUserSeeder::class, '--no-interaction' => true]);
    Artisan::call('db:seed', ['--class' => UserAppScenarioSeeder::class, '--no-interaction' => true]);

    $user = User::query()
        ->where('email', 'user@example.com')
        ->with(['media', 'addresses', 'notifications'])
        ->firstOrFail();

    expect($user->phone)->not->toBeNull();
    expect($user->phone_verified_at)->not->toBeNull();

    expect($user->getFirstMedia('primary-image'))->not->toBeNull();
    expect($user->getFirstMedia('images'))->not->toBeNull();

    expect($user->addresses)->toHaveCount(2);
    expect($user->addresses->where('is_default', true))->toHaveCount(1);

    $defaultAddress = $user->addresses->firstWhere('is_default', true);
    expect($defaultAddress)->not->toBeNull();
    expect($defaultAddress?->mobile)->not->toBeNull();
    expect($defaultAddress?->directions)->not->toBeNull();
    expect($defaultAddress?->latitude)->not->toBeNull();
    expect($defaultAddress?->longitude)->not->toBeNull();

    expect($user->notifications)->toHaveCount(3);

    $firstNotification = $user->notifications->first();
    expect(data_get($firstNotification?->data, 'title'))->not->toBe('');
    expect(data_get($firstNotification?->data, 'body'))->not->toBe('');
    expect(data_get($firstNotification?->data, 'type'))->not->toBe('');
});
