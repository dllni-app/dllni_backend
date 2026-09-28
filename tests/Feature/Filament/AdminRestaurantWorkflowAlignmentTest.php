<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Pages\RestaurantFinancialSettings;
use App\Filament\Pages\RestaurantSectionHub;
use App\Filament\Resources\Restaurants\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Restaurants\RelationManagers\RestaurantRolesRelationManager;
use App\Filament\Resources\Restaurants\RelationManagers\RestaurantStaffRelationManager;
use App\Filament\Resources\Restaurants\RestaurantResource;
use App\Models\RestaurantFinancialSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantDocument;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Filament::setCurrentPanel(filament()->getPanel('admin'));

    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('keeps documents staff and roles attached to the restaurant administration page', function (): void {
    expect(RestaurantResource::getRelations())
        ->toContain(DocumentsRelationManager::class)
        ->toContain(RestaurantStaffRelationManager::class)
        ->toContain(RestaurantRolesRelationManager::class);
});

it('creates a new restaurant commission setting without rewriting the previous setting', function (): void {
    $previous = RestaurantFinancialSetting::query()->create([
        'commission_type' => 'percent',
        'commission_value' => 5,
    ]);

    Livewire::test(RestaurantFinancialSettings::class)
        ->set('commissionType', 'percent')
        ->set('commissionValue', 7.5)
        ->call('save')
        ->assertHasNoErrors();

    expect(RestaurantFinancialSetting::query()->count())->toBe(2)
        ->and((float) $previous->fresh()->commission_value)->toBe(5.0)
        ->and((float) RestaurantFinancialSetting::query()->latest('id')->value('commission_value'))->toBe(7.5);
});

it('surfaces restaurant documents that expire within thirty days in the restaurant hub', function (): void {
    $restaurant = Restaurant::factory()->create(['name' => 'Expiry Restaurant']);

    RestaurantDocument::query()->create([
        'restaurant_id' => $restaurant->id,
        'document_type' => 'commercial_registration',
        'verification_status' => 'approved',
        'file_path' => 'documents/test.pdf',
        'expires_at' => now()->addDays(10),
    ]);

    $data = Livewire::test(RestaurantSectionHub::class)->instance()->getViewData();

    $row = collect($data['checklist'])->firstWhere('label', 'وثائق منتهية أو تنتهي خلال 30 يوم');

    expect($row)->not->toBeNull()
        ->and($row['value'])->toBe(1);
});

it('builds restaurant analytics from completed orders instead of stale aggregate tables', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    $restaurant = Restaurant::factory()->create(['user_id' => $owner->id]);

    Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed->value,
        'total_amount' => 100.50,
        'created_at' => now()->subDay(),
    ]);
    Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed->value,
        'total_amount' => 49.50,
        'created_at' => now()->subDay(),
    ]);
    Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Cancelled->value,
        'total_amount' => 999,
        'created_at' => now()->subDay(),
    ]);

    Sanctum::actingAs($owner);

    $from = now()->subDays(2)->toDateString();
    $to = now()->toDateString();

    $daily = $this->getJson("/api/v1/restaurant-owner/analytics/daily-stats?dateFrom={$from}&dateTo={$to}")
        ->assertOk()
        ->json('data');

    expect($daily)->toHaveCount(1)
        ->and($daily[0]['ordersCount'])->toBe(2)
        ->and((float) $daily[0]['revenue'])->toBe(150.0)
        ->and((float) $daily[0]['averageOrderValue'])->toBe(75.0);

    $monthly = $this->getJson("/api/v1/restaurant-owner/analytics/monthly-stats?dateFrom={$from}&dateTo={$to}")
        ->assertOk()
        ->json('data');

    expect($monthly)->toHaveCount(1)
        ->and($monthly[0]['ordersCount'])->toBe(2)
        ->and((float) $monthly[0]['revenue'])->toBe(150.0);
});
