<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Filament\Pages\CleaningOverview;
use App\Filament\Pages\RestaurantSectionHub;
use App\Filament\Pages\SupermarketSectionHub;
use App\Models\SystemAlert;
use App\Models\User;
use Database\Factories\OrderFactory;
use Modules\Resturants\Models\Order;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create([
        'email' => 'hub-pages-test@example.com',
    ]);
    $adminUser->assignRole('admin');

    expect($adminUser->fresh()->hasRole('admin', $guardName))->toBeTrue();

    $this->actingAs($adminUser);
});

it('allows an admin to load the supermarket section hub', function (): void {
    $this->get(SupermarketSectionHub::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load the restaurant section hub', function (): void {
    $this->get(RestaurantSectionHub::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load the cleaning overview command center', function (): void {
    $this->get(CleaningOverview::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load the cleaning overview when a system alert targets a restaurant order', function (): void {
    $order = OrderFactory::new()->create();

    SystemAlert::query()->create([
        'booking_id' => $order->id,
        'booking_type' => Order::class,
        'alert_type' => AlertType::DelayedRating,
        'severity' => AlertSeverity::Medium,
        'status' => SystemAlertStatus::New,
        'payload' => [],
    ]);

    $this->get(CleaningOverview::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});
