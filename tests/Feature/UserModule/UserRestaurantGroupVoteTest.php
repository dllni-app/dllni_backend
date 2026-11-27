<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\Resturants\Models\RestaurantGroupVoteOption;
use Modules\User\Services\RestaurantGroupVoteService;

it('returns vote suggestions payload', function (): void {
    $response = $this->getJson('/api/v1/user/restaurants/votes/suggestions');

    $response->assertSuccessful()->assertJsonStructure([
        'durationMinutesPresets',
        'cuisineTypes',
        'suggestions',
    ]);
});

it('intersects the public food family with indexed presentation filters', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $section = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
    $shawarma = FoodDiscoveryCategory::create(['slug' => 'shawarma-vote', 'name_ar' => 'Shawarma', 'is_active' => true]);
    $burger = FoodDiscoveryCategory::create(['slug' => 'burger-vote', 'name_ar' => 'Burger', 'is_active' => true]);
    $common = ['restaurant_id' => $restaurant->id, 'category_id' => $section->id, 'catalog_role' => 'independent',
        'classification_status' => 'reviewed', 'product_kind' => 'main_dish', 'is_available' => true, 'stock_quantity' => 10];
    $sandwich = Product::factory()->create($common + ['primary_food_category_id' => $shawarma->id, 'item_type' => 'sandwich']);
    Product::factory()->create($common + ['primary_food_category_id' => $shawarma->id, 'item_type' => 'meal']);
    Product::factory()->create($common + ['primary_food_category_id' => $burger->id, 'item_type' => 'sandwich']);
    $url = '/api/v1/user/restaurants/votes/suggestions?'.http_build_query([
        'foodCategoryId' => $shawarma->id, 'itemTypes' => ['sandwich'],
    ]);
    $payload = $this->getJson($url)->assertOk()->json();
    expect(array_column($payload['suggestions'], 'id'))->toBe([$sandwich->id])
        ->and($payload['suggestions'][0]['itemType'])->toBe('sandwich')
        ->and(array_column($payload['foodCategories'], 'id'))->toContain($shawarma->id, $burger->id);
    $section->update(['is_active' => false]);
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'suggestions');
});

it('filters suggestions by cuisine type', function (): void {
    $cuisine = CuisineType::query()->create([
        'name' => 'Italian Test',
        'slug' => 'italian-test-'.uniqid(),
    ]);

    $restaurantMatch = Restaurant::factory()->create(['is_active' => true]);
    $restaurantMatch->cuisineTypes()->attach($cuisine->id);

    $restaurantOther = Restaurant::factory()->create(['is_active' => true]);

    $productMatch = Product::factory()->create([
        'restaurant_id' => $restaurantMatch->id,
        'name' => 'Margherita Pizza Vote',
        'is_available' => true,
    ]);

    Product::factory()->create([
        'restaurant_id' => $restaurantOther->id,
        'name' => 'Other Burger Vote',
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/restaurants/votes/suggestions?cuisineTypeId='.$cuisine->id);

    $response->assertSuccessful();
    $ids = collect($response->json('suggestions'))->pluck('id')->all();
    expect($ids)->toContain($productMatch->id);
});

it('requires authentication to create a vote', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $this->postJson('/api/v1/user/restaurants/votes', [
        'durationMinutes' => 30,
        'options' => [
            ['label' => 'A', 'productId' => $p1->id],
            ['label' => 'B', 'productId' => $p2->id],
        ],
    ])->assertUnauthorized();
});

it('creates a group vote and returns payload', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $response = $this->postJson('/api/v1/user/restaurants/votes', [
        'durationMinutes' => 30,
        'foodCategoryHint' => 'burger',
        'options' => [
            ['label' => 'Option A', 'productId' => $p1->id],
            ['label' => 'Option B', 'productId' => $p2->id],
        ],
    ]);

    $response->assertCreated()->assertJsonPath('data.vote.status', 'active');
    $voteId = (int) $response->json('data.vote.id');
    expect($voteId)->toBeGreaterThan(0);

    expect(RestaurantGroupVote::query()->where('id', $voteId)->exists())->toBeTrue();
    expect(RestaurantGroupVoteOption::query()->where('vote_id', $voteId)->count())->toBe(2);
});

it('shows vote without authentication', function (): void {
    $creator = User::factory()->create();

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $vote = app(RestaurantGroupVoteService::class)->create(
        creatorUserId: (int) $creator->id,
        durationMinutes: 60,
        options: [
            ['label' => 'A', 'productId' => $p1->id],
            ['label' => 'B', 'productId' => $p2->id],
        ],
    );

    $optionAId = (int) $vote->options->firstWhere('label', 'A')->id;

    $show = $this->getJson('/api/v1/user/restaurants/votes/'.$vote->id);
    $show->assertSuccessful()->assertJsonPath('data.vote.id', $vote->id);
    expect($show->json('data.vote.isCreator'))->toBeFalse();

    $voter = User::factory()->create();
    Sanctum::actingAs($voter);

    $this->postJson('/api/v1/user/restaurants/votes/'.$vote->id.'/ballots', [
        'optionId' => $optionAId,
    ])->assertSuccessful();

    $updated = collect($this->getJson('/api/v1/user/restaurants/votes/'.$vote->id)->json('data.options'));
    expect($updated->firstWhere('id', $optionAId)['voteCount'])->toBe(1);
});

it('only creator can end vote early', function (): void {
    $creator = User::factory()->create();
    $intruder = User::factory()->create();

    Sanctum::actingAs($creator);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $create = $this->postJson('/api/v1/user/restaurants/votes', [
        'durationMinutes' => 120,
        'options' => [
            ['label' => 'A', 'productId' => $p1->id],
            ['label' => 'B', 'productId' => $p2->id],
        ],
    ]);

    $voteId = (int) $create->json('data.vote.id');
    $optionAId = (int) collect($create->json('data.options'))->firstWhere('label', 'A')['id'];

    Sanctum::actingAs($intruder);

    $this->postJson('/api/v1/user/restaurants/votes/'.$voteId.'/end')
        ->assertUnprocessable();

    Sanctum::actingAs($creator);

    $this->postJson('/api/v1/user/restaurants/votes/'.$voteId.'/ballots', [
        'optionId' => $optionAId,
    ])->assertSuccessful();

    $end = $this->postJson('/api/v1/user/restaurants/votes/'.$voteId.'/end');
    $end->assertSuccessful()->assertJsonPath('data.vote.status', 'ended');
    expect($end->json('data.winner'))->not->toBeNull();
});

it('finalizes expired votes on read', function (): void {
    $creator = User::factory()->create();
    Sanctum::actingAs($creator);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $create = $this->postJson('/api/v1/user/restaurants/votes', [
        'durationMinutes' => 30,
        'options' => [
            ['label' => 'A', 'productId' => $p1->id],
            ['label' => 'B', 'productId' => $p2->id],
        ],
    ]);

    $voteId = (int) $create->json('data.vote.id');
    $optionAId = (int) collect($create->json('data.options'))->firstWhere('label', 'A')['id'];

    $voter = User::factory()->create();
    Sanctum::actingAs($voter);
    $this->postJson('/api/v1/user/restaurants/votes/'.$voteId.'/ballots', [
        'optionId' => $optionAId,
    ])->assertSuccessful();

    $vote = RestaurantGroupVote::query()->findOrFail($voteId);
    $vote->update([
        'ends_at' => now()->subMinute(),
    ]);

    $show = $this->getJson('/api/v1/user/restaurants/votes/'.$voteId);
    $show->assertSuccessful()->assertJsonPath('data.vote.status', 'ended');
    expect($show->json('data.winner.optionId'))->toBe($optionAId);
});

it('requires authentication to list my active votes', function (): void {
    $this->getJson('/api/v1/user/restaurants/votes/active')->assertUnauthorized();
});

it('creator can invite users and invited user can see active vote', function (): void {
    $creator = User::factory()->create();
    $invitee = User::factory()->create();

    Sanctum::actingAs($creator);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $create = $this->postJson('/api/v1/user/restaurants/votes', [
        'durationMinutes' => 30,
        'options' => [
            ['label' => 'A', 'productId' => $p1->id],
            ['label' => 'B', 'productId' => $p2->id],
        ],
    ])->assertCreated();

    $voteId = (int) $create->json('data.vote.id');

    $invite = $this->postJson('/api/v1/user/restaurants/votes/'.$voteId.'/invite', [
        'userIds' => [$invitee->id],
    ]);

    $invite->assertSuccessful()->assertJsonPath('data.invitedUserIds.0', $invitee->id);

    Sanctum::actingAs($invitee);

    $activeVotes = $this->getJson('/api/v1/user/restaurants/votes/active');
    $activeVotes->assertSuccessful()->assertJsonCount(1, 'data');
    $activeVotes->assertJsonPath('data.0.vote.id', $voteId);
    $activeVotes->assertJsonPath('data.0.vote.isInvited', true);
});

it('only creator can invite users to a vote', function (): void {
    $creator = User::factory()->create();
    $intruder = User::factory()->create();
    $invitee = User::factory()->create();

    Sanctum::actingAs($creator);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $create = $this->postJson('/api/v1/user/restaurants/votes', [
        'durationMinutes' => 30,
        'options' => [
            ['label' => 'A', 'productId' => $p1->id],
            ['label' => 'B', 'productId' => $p2->id],
        ],
    ])->assertCreated();

    $voteId = (int) $create->json('data.vote.id');

    Sanctum::actingAs($intruder);

    $this->postJson('/api/v1/user/restaurants/votes/'.$voteId.'/invite', [
        'userIds' => [$invitee->id],
    ])->assertUnprocessable();
});

it('active votes endpoint excludes ended votes', function (): void {
    $creator = User::factory()->create();

    Sanctum::actingAs($creator);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $create = $this->postJson('/api/v1/user/restaurants/votes', [
        'durationMinutes' => 30,
        'options' => [
            ['label' => 'A', 'productId' => $p1->id],
            ['label' => 'B', 'productId' => $p2->id],
        ],
    ])->assertCreated();

    $voteId = (int) $create->json('data.vote.id');

    $this->postJson('/api/v1/user/restaurants/votes/'.$voteId.'/end')->assertSuccessful();

    $this->getJson('/api/v1/user/restaurants/votes/active')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});
