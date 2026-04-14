<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CleaningWorkerAndSellerSeeder;
use Database\Seeders\DashboardPermissionsSeeder;
use Database\Seeders\UserNotificationsSeeder;
use Database\Seeders\VerifiedUserSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => DashboardPermissionsSeeder::class, '--no-interaction' => true]);
    Artisan::call('db:seed', ['--class' => AdminUserSeeder::class, '--no-interaction' => true]);
    Artisan::call('db:seed', ['--class' => CleaningWorkerAndSellerSeeder::class, '--no-interaction' => true]);
    Artisan::call('db:seed', ['--class' => VerifiedUserSeeder::class, '--no-interaction' => true]);
});

it('seeds notifications for the user app endpoints across target accounts', function (): void {
    Artisan::call('db:seed', ['--class' => UserNotificationsSeeder::class, '--no-interaction' => true]);

    $users = [
        'admin@admin.com' => 3,
        '+963944100001' => 3,
        '+963944100002' => 3,
        '+963944100003' => 3,
        'user@dllni.sy' => 4,
    ];

    foreach ($users as $identifier => $expectedCount) {
        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->firstOrFail();

        expect($user->notifications()->count())->toBe($expectedCount);
        expect($user->notifications()->whereNull('read_at')->count())->toBeGreaterThan(0);
    }
});

it('is idempotent when seeded twice', function (): void {
    Artisan::call('db:seed', ['--class' => UserNotificationsSeeder::class, '--no-interaction' => true]);
    Artisan::call('db:seed', ['--class' => UserNotificationsSeeder::class, '--no-interaction' => true]);

    expect(User::query()->where('email', 'user@dllni.sy')->firstOrFail()->notifications()->count())->toBe(4);
    expect(User::query()->where('email', 'admin@admin.com')->firstOrFail()->notifications()->count())->toBe(3);
});
