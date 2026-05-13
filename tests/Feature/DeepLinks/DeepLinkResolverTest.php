<?php

declare(strict_types=1);

use App\Models\DeepLinkShortUrl;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\RestaurantGroupOrderStatus;
use Modules\Resturants\Enums\RestaurantGroupVoteStatus;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\Resturants\Models\RestaurantGroupOrderParticipant;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\Supermarket\Models\SmStore;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

it('resolves a restaurant slug deep link', function (): void {
    $restaurant = Restaurant::factory()->create([
        'slug' => 'al-atrash',
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $response = postJson('/api/v1/deep-links/resolve', [
        'url' => 'https://dllni.mustafafares.com/restaurant/al-atrash?source=whatsapp',
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'restaurant')
        ->assertJsonPath('id', $restaurant->id)
        ->assertJsonPath('slug', 'al-atrash')
        ->assertJsonPath('status', 'ok');
});

it('returns forbidden for hidden restaurant', function (): void {
    $restaurant = Restaurant::factory()->create([
        'slug' => 'hidden-restaurant',
        'is_active' => false,
    ]);

    $response = postJson('/api/v1/deep-links/resolve', [
        'url' => '/restaurant/' . $restaurant->slug,
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'restaurant')
        ->assertJsonPath('status', 'forbidden');
});

it('resolves product id and returns canonical format', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $response = postJson('/api/v1/deep-links/resolve', [
        'url' => '/product/' . $product->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'product')
        ->assertJsonPath('id', $product->id)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('canonical_url', 'https://dllni.mustafafares.com/product/' . $product->id);
});

it('returns expired for ended vote', function (): void {
    $user = User::factory()->create();

    $vote = RestaurantGroupVote::query()->create([
        'user_id' => $user->id,
        'duration_minutes' => 30,
        'ends_at' => now()->subMinutes(2),
        'status' => RestaurantGroupVoteStatus::Ended,
    ]);

    $response = postJson('/api/v1/deep-links/resolve', [
        'url' => '/vote/' . $vote->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'vote')
        ->assertJsonPath('status', 'expired');
});

it('enforces private restriction for numeric group order links', function (): void {
    $organizer = User::factory()->create();
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $groupOrder = RestaurantGroupOrder::query()->create([
        'user_id' => $organizer->id,
        'restaurant_id' => $restaurant->id,
        'share_token' => 'abcdef1234567890abcdef1234567890',
        'status' => RestaurantGroupOrderStatus::Active,
        'ends_at' => now()->addMinutes(30),
    ]);

    RestaurantGroupOrderParticipant::query()->create([
        'group_order_id' => $groupOrder->id,
        'user_id' => $organizer->id,
        'status' => 'joined',
    ]);

    postJson('/api/v1/deep-links/resolve', [
        'url' => '/group-order/' . $groupOrder->id,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'forbidden')
        ->assertJsonPath('requires_auth', true);

    $participant = User::factory()->create();
    RestaurantGroupOrderParticipant::query()->create([
        'group_order_id' => $groupOrder->id,
        'user_id' => $participant->id,
        'status' => 'joined',
    ]);

    Sanctum::actingAs($participant);

    postJson('/api/v1/deep-links/resolve', [
        'url' => '/group-order/' . $groupOrder->id,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('resolves tokenized group order links without auth', function (): void {
    $organizer = User::factory()->create();
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $groupOrder = RestaurantGroupOrder::query()->create([
        'user_id' => $organizer->id,
        'restaurant_id' => $restaurant->id,
        'share_token' => 'feedabc1234567890feedabc12345678',
        'status' => RestaurantGroupOrderStatus::Active,
        'ends_at' => now()->addMinutes(20),
    ]);

    $response = postJson('/api/v1/deep-links/resolve', [
        'url' => '/group-order/' . $groupOrder->share_token,
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'group-order')
        ->assertJsonPath('id', $groupOrder->id)
        ->assertJsonPath('slug', $groupOrder->share_token)
        ->assertJsonPath('status', 'ok');
});

it('joins authenticated user as participant when resolving tokenized group order link', function (): void {
    $organizer = User::factory()->create();
    $invitee = User::factory()->create();
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $groupOrder = RestaurantGroupOrder::query()->create([
        'user_id' => $organizer->id,
        'restaurant_id' => $restaurant->id,
        'share_token' => 'aabbccddeeff00112233445566778899',
        'status' => RestaurantGroupOrderStatus::Active,
        'ends_at' => now()->addMinutes(25),
    ]);

    RestaurantGroupOrderParticipant::query()->create([
        'group_order_id' => $groupOrder->id,
        'user_id' => $organizer->id,
        'status' => 'joined',
    ]);

    Sanctum::actingAs($invitee);

    postJson('/api/v1/deep-links/resolve', [
        'url' => '/group-order/' . $groupOrder->share_token,
    ])
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('id', $groupOrder->id);

    expect(
        RestaurantGroupOrderParticipant::query()
            ->where('group_order_id', $groupOrder->id)
            ->where('user_id', $invitee->id)
            ->exists()
    )->toBeTrue();
});

it('tracks deep link events endpoint', function (): void {
    $response = postJson('/api/v1/deep-links/events', [
        'action' => 'click',
        'url' => 'https://dllni.mustafafares.com/restaurant/non-existing',
        'source' => 'whatsapp',
        'medium' => 'social',
        'campaign' => 'spring',
    ]);

    $response->assertOk()->assertJsonPath('status', 'ok');
});

it('returns json validation errors for deep link endpoints without redirect', function (): void {
    $resolveResponse = post('/api/v1/deep-links/resolve', []);
    $resolveResponse->assertStatus(422);
    expect((string) $resolveResponse->headers->get('content-type'))->toContain('application/json');

    $eventsResponse = post('/api/v1/deep-links/events', []);
    $eventsResponse->assertStatus(422);
    expect((string) $eventsResponse->headers->get('content-type'))->toContain('application/json');
});

it('redirects short links to target when active', function (): void {
    DeepLinkShortUrl::query()->create([
        'code' => 'go123',
        'target_url' => 'https://dllni.mustafafares.com/product/99',
        'is_active' => true,
    ]);

    get('/s/go123')->assertRedirect('https://dllni.mustafafares.com/product/99');
});

it('resolves short link code to target resource metadata', function (): void {
    $restaurant = Restaurant::factory()->create([
        'slug' => 'resolver-short-restaurant',
        'is_active' => true,
    ]);

    DeepLinkShortUrl::query()->create([
        'code' => 'go-resolve-1',
        'target_url' => 'https://dllni.mustafafares.com/restaurant/' . $restaurant->slug,
        'is_active' => true,
    ]);

    $response = postJson('/api/v1/deep-links/resolve', [
        'url' => 'https://dllni.mustafafares.com/s/go-resolve-1',
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'restaurant')
        ->assertJsonPath('id', $restaurant->id)
        ->assertJsonPath('slug', $restaurant->slug)
        ->assertJsonPath('status', 'ok');
});

it('resolves API-shaped product links', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $response = postJson('/api/v1/deep-links/resolve', [
        'url' => 'https://dllni.mustafafares.com/api/v1/user/products/' . $product->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'product')
        ->assertJsonPath('id', $product->id)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('canonical_url', 'https://dllni.mustafafares.com/product/' . $product->id);
});

it('resolves API-shaped supermarket store links', function (): void {
    $owner = User::factory()->create();

    $store = SmStore::query()->create([
        'owner_user_id' => $owner->id,
        'name' => 'Deep Link Store',
        'slug' => 'deep-link-store',
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $response = postJson('/api/v1/deep-links/resolve', [
        'url' => 'https://dllni.mustafafares.com/api/v1/user/supermarket/stores/' . $store->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'store')
        ->assertJsonPath('target', 'supermarket_store')
        ->assertJsonPath('id', $store->id)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('canonical_url', 'https://dllni.mustafafares.com/store/' . $store->id);
});

it('returns forbidden when product exists but is not visible', function (): void {
    $restaurant = Restaurant::factory()->create([
        'is_active' => false,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    postJson('/api/v1/deep-links/resolve', [
        'url' => 'https://dllni.mustafafares.com/product/' . $product->id,
    ])
        ->assertOk()
        ->assertJsonPath('type', 'product')
        ->assertJsonPath('id', $product->id)
        ->assertJsonPath('status', 'forbidden');
});
