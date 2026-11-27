<?php

declare(strict_types=1);

use App\Filament\Resources\RestaurantFoodCategories\Pages\EditRestaurantFoodCategory;
use App\Filament\Resources\RestaurantProducts\Pages\EditRestaurantProductClassification;
use App\Filament\Resources\RestaurantProducts\RestaurantProductResource;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Filament::setCurrentPanel(filament()->getPanel('admin'));
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);
});

it('reviews food presentation and portions via the authenticated admin form', function (): void {
    $family = FoodDiscoveryCategory::create(['slug' => 'shawarma', 'name_ar' => 'شاورما', 'is_active' => true]);
    $product = Product::factory()->create(['catalog_role' => 'independent', 'classification_status' => 'unreviewed']);
    Livewire::test(EditRestaurantProductClassification::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'catalog_role' => 'independent',
            'product_kind' => 'combo_meal',
            'item_type' => 'family_meal',
            'primary_food_category_id' => $family->id,
            'servings' => 1,
            'included_components' => ['side', 'beverage'],
        ])
        ->call('save')->assertHasFormErrors(['servings']);
    expect($product->fresh()->classification_status)->toBe('unreviewed');

    Livewire::test(EditRestaurantProductClassification::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'catalog_role' => 'independent',
            'product_kind' => 'combo_meal',
            'item_type' => 'family_meal',
            'primary_food_category_id' => $family->id,
            'servings' => 4,
            'included_components' => ['side', 'beverage'],
        ])
        ->call('save')->assertHasNoFormErrors();
    $product->refresh();
    expect($product->classification_status)->toBe('reviewed')
        ->and($product->item_type)->toBe('family_meal')
        ->and($product->servings)->toBe(4)
        ->and($product->included_components)->toBe(['side', 'beverage'])
        ->and($product->classified_at)->not->toBeNull();

    Livewire::test(EditRestaurantProductClassification::class, ['record' => $product->getRouteKey()])
        ->fillForm(['catalog_role' => 'modifier_only'])
        ->call('save')->assertHasNoFormErrors();
    $product->refresh();
    expect($product->catalog_role)->toBe('modifier_only')
        ->and($product->primary_food_category_id)->toBeNull()
        ->and($product->item_type)->toBe('other')
        ->and($product->servings)->toBeNull();
});

it('edits family aliases without changing the immutable slug', function (): void {
    $family = FoodDiscoveryCategory::create(['slug' => 'burger', 'name_ar' => 'برغر', 'is_active' => true]);
    Livewire::test(EditRestaurantFoodCategory::class, ['record' => $family->getRouteKey()])
        ->fillForm(['name_ar' => 'برغر', 'name_en' => 'Burger', 'aliases' => ['برجر', 'همبرغر'], 'is_active' => true, 'sort_order' => 1])
        ->call('save')->assertHasNoFormErrors();
    expect($family->fresh()->aliases)->toBe(['برجر', 'همبرغر'])
        ->and($family->fresh()->slug)->toBe('burger');
});

it('does not let a non-admin restaurant owner approve a product through the admin resource', function (): void {
    $product = Product::factory()->create();
    $owner = $product->restaurant->user;
    $this->actingAs($owner);
    expect(RestaurantProductResource::canEdit($product))->toBeFalse();
});
