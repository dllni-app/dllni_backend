<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Resources\SystemUsers\Pages\ViewSystemUser;
use App\Models\User;
use App\Models\Worker;
use App\Services\AdminAccountPasswordResetService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('Customer Support', 'web');
});

it('allows the platform admin to reset a customer password without OTP and revokes existing tokens', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $customer = User::factory()->create(['module_type' => null]);
    $customer->createToken('existing-customer-token');

    $previousPassword = $customer->password;

    app(AdminAccountPasswordResetService::class)->reset($admin, $customer, 'NewSecurePassword123');

    $customer->refresh();

    expect($customer->password)->not->toBe($previousPassword)
        ->and(Hash::check('NewSecurePassword123', $customer->password))->toBeTrue()
        ->and($customer->tokens()->count())->toBe(0)
        ->and($customer->phone_verified_at)->toBeNull();
});

it('resets the linked login account for a cleaning worker', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $linkedUser = User::factory()->create(['module_type' => UserModuleType::CleaningWorker->value]);
    $worker = Worker::factory()->create(['user_id' => $linkedUser->id]);
    $linkedUser->createToken('existing-worker-token');

    app(AdminAccountPasswordResetService::class)->reset($admin, $worker, 'NewWorkerPassword123');

    expect(Hash::check('NewWorkerPassword123', $linkedUser->refresh()->password))->toBeTrue()
        ->and($linkedUser->tokens()->count())->toBe(0);
});

it('forbids support staff and non-admin users from resetting passwords', function (): void {
    $support = User::factory()->create();
    $support->assignRole('Customer Support');
    $customer = User::factory()->create(['module_type' => null]);

    expect(app(AdminAccountPasswordResetService::class)->canReset($support, $customer))->toBeFalse();

    app(AdminAccountPasswordResetService::class)->reset($support, $customer, 'NewSecurePassword123');
})->throws(AuthorizationException::class);

it('never allows resetting privileged managers or other merchant accounts', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $manager = User::factory()->create(['module_type' => null]);
    $manager->assignRole('Customer Support');
    $seller = User::factory()->create(['module_type' => UserModuleType::RestaurantSeller->value]);

    $reset = app(AdminAccountPasswordResetService::class);

    expect($reset->canReset($admin, $manager))->toBeFalse()
        ->and($reset->canReset($admin, $seller))->toBeFalse();

    $reset->reset($admin, $manager, 'NewSecurePassword123');
})->throws(AuthorizationException::class);

it('rejects weak passwords and leaves existing credentials unchanged', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $customer = User::factory()->create(['module_type' => null]);
    $previousHash = $customer->password;

    try {
        app(AdminAccountPasswordResetService::class)->reset($admin, $customer, 'short');
    } catch (ValidationException) {
        expect($customer->refresh()->password)->toBe($previousHash);

        return;
    }

    test()->fail('Expected validation to reject a short password.');
});

it('offers password reset on the system user detail page and updates the login password', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $customer = User::factory()->create(['module_type' => null]);

    Livewire::test(ViewSystemUser::class, ['record' => $customer->getRouteKey()])
        ->assertActionExists('resetAccountPassword')
        ->callAction('resetAccountPassword', data: [
            'new_password' => 'DashboardReset123!',
            'new_password_confirmation' => 'DashboardReset123!',
        ])
        ->assertHasNoFormErrors();

    expect(Hash::check('DashboardReset123!', $customer->refresh()->password))->toBeTrue();
});
