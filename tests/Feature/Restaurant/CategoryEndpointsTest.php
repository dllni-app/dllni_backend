<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($owner);
});

it('lists categories', function () {
    Category::factory()->count(3)->create(['restaurant_id' => $this->restaurant->id]);

    $response = $this->getJson('/api/v1/categories');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toHaveCount(3);
});

it('creates a category', function () {
    $payload = [
        'restaurantId' => $this->restaurant->id,
        'name' => 'Desserts',
        'slug' => 'desserts-'.Str::random(4),
        'sortOrder' => 5,
    ];

    $response = $this->postJson('/api/v1/categories', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('categories', [
        'name' => 'Desserts',
        'restaurant_id' => $this->restaurant->id,
    ]);
});

it('shows a category', function () {
    $category = Category::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Main Course',
    ]);

    $response = $this->getJson("/api/v1/categories/{$category->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($category->id);
    expect($response->json('data.name'))->toBe('Main Course');
});

it('updates a category', function () {
    $category = Category::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Old Name',
    ]);

    $response = $this->putJson("/api/v1/categories/{$category->id}", [
        'restaurantId' => $this->restaurant->id,
        'name' => 'Updated Category',
        'slug' => $category->slug,
        'sortOrder' => 10,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Updated Category',
        'restaurant_id' => $this->restaurant->id,
    ]);
});

it('deletes a category', function () {
    $category = Category::factory()->create([
        'restaurant_id' => $this->restaurant->id,
    ]);

    $response = $this->deleteJson("/api/v1/categories/{$category->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

it('reorders all siblings atomically and rejects stale or foreign IDs', function () {
    $a = Category::factory()->create(['restaurant_id' => $this->restaurant->id, 'sort_order' => 0]);
    $b = Category::factory()->create(['restaurant_id' => $this->restaurant->id, 'sort_order' => 1]);
    $child = Category::factory()->create(['restaurant_id' => $this->restaurant->id, 'parent_id' => $a->id]);
    $foreign = Category::factory()->create();
    $endpoint = '/api/v1/categories/reorder';
    $body = ['parentId' => null, 'expectedIds' => [$a->id, $b->id], 'orderedIds' => [$b->id, $a->id]];
    $this->putJson($endpoint, $body)->assertNoContent();
    expect($a->fresh()->sort_order)->toBe(1)->and($b->fresh()->sort_order)->toBe(0);
    $this->putJson($endpoint, $body)->assertUnprocessable()->assertJsonValidationErrors('expectedIds');
    foreach ([[$a->id, $foreign->id], [$a->id, $child->id], [$a->id]] as $invalid) {
        $this->putJson($endpoint, ['parentId' => null, 'expectedIds' => [$b->id, $a->id], 'orderedIds' => $invalid])
            ->assertUnprocessable()->assertJsonValidationErrors('orderedIds');
        expect($a->fresh()->sort_order)->toBe(1)->and($b->fresh()->sort_order)->toBe(0);
    }
    $this->putJson($endpoint, ['parentId' => null, 'expectedIds' => [$b->id, $a->id], 'orderedIds' => [$a->id, $a->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('orderedIds.0');
    expect($a->fresh()->sort_order)->toBe(1)->and($b->fresh()->sort_order)->toBe(0);
    $this->putJson($endpoint, ['parentId' => $foreign->id, 'expectedIds' => [$foreign->id], 'orderedIds' => [$foreign->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('parentId');
    $otherChild = Category::factory()->create(['restaurant_id' => $this->restaurant->id, 'parent_id' => $a->id, 'sort_order' => $child->sort_order + 1]);
    $this->putJson($endpoint, ['parentId' => $a->id, 'expectedIds' => [$child->id, $otherChild->id], 'orderedIds' => [$otherChild->id, $child->id]])
        ->assertNoContent();
    expect($child->fresh()->sort_order)->toBe(1)->and($otherChild->fresh()->sort_order)->toBe(0)
        ->and($a->fresh()->sort_order)->toBe(1);
});
