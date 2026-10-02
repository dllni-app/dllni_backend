<?php

declare(strict_types=1);

use App\Filament\Resources\Restaurants\Pages\ViewRestaurant;
use App\Filament\Resources\SmStores\Pages\ViewSmStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantReputationLog;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreTrustLog;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('manages restaurant suspension and reputation through audited actions', function (): void {
    $restaurant = Restaurant::factory()->create([
        'reputation_score' => 80,
        'is_active' => true,
        'is_featured' => false,
        'suspension_until' => null,
    ]);

    Livewire::test(ViewRestaurant::class, ['record' => $restaurant->getRouteKey()])
        ->callAction('suspend_restaurant', data: [
            'suspension_until' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'reason' => 'Compliance review',
        ])
        ->assertHasNoActionErrors();

    expect($restaurant->fresh()->suspension_until)->not->toBeNull();

    Livewire::test(ViewRestaurant::class, ['record' => $restaurant->getRouteKey()])
        ->callAction('adjust_restaurant_reputation', data: [
            'score' => 65,
            'reason' => 'Manual evidence review',
        ])
        ->assertHasNoActionErrors();

    expect((int) $restaurant->fresh()->reputation_score)->toBe(65)
        ->and(RestaurantReputationLog::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('score_delta', -15)
            ->exists())->toBeTrue()
        ->and(Activity::query()
            ->where('log_name', 'merchant_governance')
            ->where('subject_id', $restaurant->id)
            ->count())->toBeGreaterThanOrEqual(2);
});

it('manages supermarket store suspension and trust through audited actions', function (): void {
    $store = SmStore::factory()->create([
        'trust_score' => 90,
        'is_active' => true,
        'is_featured' => false,
        'suspension_until' => null,
    ]);

    Livewire::test(ViewSmStore::class, ['record' => $store->getRouteKey()])
        ->callAction('suspend_store', data: [
            'suspension_until' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'reason' => 'Store verification issue',
        ])
        ->assertHasNoActionErrors();

    expect($store->fresh()->suspension_until)->not->toBeNull();

    Livewire::test(ViewSmStore::class, ['record' => $store->getRouteKey()])
        ->callAction('adjust_store_trust', data: [
            'score' => 70,
            'reason' => 'Manual trust review',
        ])
        ->assertHasNoActionErrors();

    expect((int) $store->fresh()->trust_score)->toBe(70)
        ->and(SmStoreTrustLog::query()
            ->where('store_id', $store->id)
            ->where('event_type', 'admin_manual_adjustment')
            ->where('score_after', 70)
            ->exists())->toBeTrue()
        ->and(Activity::query()
            ->where('log_name', 'merchant_governance')
            ->where('subject_id', $store->id)
            ->count())->toBeGreaterThanOrEqual(2);
});
