<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\OperatingHour;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Http\Resources\RestaurantV2RestaurantResource;
use Modules\User\Services\CommerceCheckoutQuoteService;
use Modules\User\Services\RestaurantV2Availability;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class RestaurantV2ReadContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('local', app()->environment(), 'This scoped suite is allowed only against APP_ENV=local.');
        self::assertSame('mysql', config('database.default'), 'Refusing a substitute or in-memory database.');
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], 'Refusing a non-local DB host.');
        self::assertSame('alnadha_worker_test', config('database.connections.mysql.database'), 'Unexpected local test database.');

        $target = DB::selectOne('SELECT DATABASE() AS selected_db, @@hostname AS db_host, @@port AS db_port');
        self::assertSame('alnadha_worker_test', $target->selected_db);
        self::assertContains($target->db_host, ['localhost', '127.0.0.1', 'DESKTOP-E9S09JG']);
        self::assertSame(3306, (int) $target->db_port);
    }

    public function test_public_home_sections_and_listing_read_routes_are_guest_accessible_and_no_store(): void
    {
        $this->getJson('/api/v2/user/restaurants/home?perPage=2')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['data' => ['sections' => ['promotions', 'cuisines', 'restaurants', 'dishes']]]);

        foreach (['promotions', 'cuisines', 'restaurants', 'dishes'] as $section) {
            $response = $this->getJson('/api/v2/user/restaurants/home/'.$section.'?perPage=2');
            $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonStructure(['data' => ['items']]);
        }

        $this->getJson('/api/v2/user/restaurants/discovery?perPage=2')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['data', 'meta' => ['currentPage', 'perPage', 'lastPage', 'total']]);
    }

    public function test_coordinates_must_be_paired_and_feature_gate_fails_closed(): void
    {
        $this->getJson('/api/v2/user/restaurants/discovery?latitude=36.2')
            ->assertUnprocessable()->assertJsonValidationErrors(['latitude']);

        config(['restaurant_v2.enabled' => false]);
        $this->getJson('/api/v2/user/restaurants/home')->assertNotFound();
    }

    public function test_merchant_scoped_dish_search_validates_scope_and_never_leaks_other_merchants(): void
    {
        $base = '/api/v2/user/restaurants/search?'.http_build_query([
            'q' => 'burger', 'scope' => 'dishes', 'restaurantId' => 1, 'perPage' => 2,
        ]);
        foreach ([1, 2] as $page) {
            $response = $this->getJson($base.'&dishPage='.$page)->assertOk();
            self::assertTrue(collect($response->json('data'))->every(
                fn (array $dish): bool => (int) $dish['restaurantId'] === 1,
            ));
        }

        $this->getJson('/api/v2/user/restaurants/search?q=burger&restaurantId=1')
            ->assertUnprocessable()->assertJsonValidationErrors(['restaurantId']);
        $this->getJson('/api/v2/user/restaurants/search?q=burger&scope=restaurants&restaurantId=1')
            ->assertUnprocessable()->assertJsonValidationErrors(['restaurantId']);
        $this->getJson('/api/v2/user/restaurants/search?q=burger&scope=dishes&restaurantId=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['restaurantId']);
        $this->getJson('/api/v2/user/restaurants/search?q=burger&scope=dishes&restaurantId=999999999')
            ->assertNotFound();
    }

    public function test_detail_is_flat_and_menu_category_page_is_bounded_and_explicit(): void
    {
        $detail = $this->getJson('/api/v2/user/restaurants/1?reviewsPerPage=2')->assertOk();
        $detail->assertJsonStructure(['data' => ['id', 'name', 'rating', 'ratingSummary', 'categories', 'popularProducts', 'reviews', 'reviewsMeta']]);
        self::assertArrayNotHasKey('restaurant', $detail->json('data'));
        $list = $this->getJson('/api/v2/user/restaurants/discovery?perPage=50')->assertOk();
        $card = collect($list->json('data'))->firstWhere('id', $detail->json('data.id'));
        self::assertNotNull($card, 'Detail merchant should be present in the bounded local discovery page.');
        self::assertSame($card['rating'], $detail->json('data.rating'));

        $menu = $this->getJson('/api/v2/user/restaurants/1/menu?perCategory=2')->assertOk();
        self::assertNotEmpty($menu->json('data.categories'));
        $categoryId = $menu->json('data.categories.0.id');

        $page = $this->getJson('/api/v2/user/restaurants/1/menu?categoryId='.$categoryId.'&page=2&perCategory=2')
            ->assertOk()->assertJsonStructure(['data' => ['categories'], 'meta' => ['currentPage', 'perPage', 'lastPage', 'total']]);
        self::assertSame((int) $categoryId, $page->json('data.categories.0.id'));
        self::assertSame(2, $page->json('meta.currentPage'));
    }

    public function test_list_rating_uses_public_review_aggregate_instead_of_stored_restaurant_average(): void
    {
        $restaurant = new Restaurant([
            'id' => 987654,
            'slug' => 'rating-source-contract',
            'name' => 'Rating source contract',
            'average_rating' => '4.70',
            'total_reviews' => 3,
        ]);
        $restaurant->setAttribute('reviews_avg_rating', '4.50');
        $restaurant->setAttribute('reviews_count', 4);
        $restaurant->setRelation('offers', collect());
        $restaurant->setRelation('cuisineTypes', collect());
        $restaurant->setRelation('operatingHours', collect());
        $restaurant->setAttribute('isFavoritedByUser', false);

        $serialized = RestaurantV2RestaurantResource::make($restaurant)->resolve(request());

        self::assertSame(['average' => '4.50', 'total' => 4], $serialized['rating']);
    }

    public function test_nearest_sort_executes_coordinate_distance_queries_and_validates_bounds(): void
    {
        $merchant = $this->getJson('/api/v2/user/restaurants/1')->assertOk()->json('data');
        $latitude = $merchant['latitude'] ?? 36.20;
        $longitude = $merchant['longitude'] ?? 37.13;

        $restaurants = $this->getJson('/api/v2/user/restaurants/discovery?latitude='.$latitude.'&longitude='.$longitude.'&sort=nearest&perPage=10')
            ->assertOk()
            ->json('data');
        $distances = collect($restaurants)->pluck('distanceKm')->filter(fn ($distance): bool => $distance !== null)->map(fn ($distance): float => (float) $distance)->all();
        $sortedDistances = $distances;
        sort($sortedDistances);
        self::assertSame($sortedDistances, $distances, 'Nearest restaurants should be sorted by ascending distance.');
        self::assertTrue(collect($distances)->every(fn (float $distance): bool => $distance >= 0 && $distance <= 20037.6));

        $menu = $this->getJson('/api/v2/user/restaurants/1/menu?perCategory=1')->assertOk()->json('data');
        $query = $menu['categories'][0]['products'][0]['name'] ?? 'burger';
        $dishes = $this->getJson('/api/v2/user/restaurants/search?'.http_build_query([
            'q' => $query,
            'scope' => 'dishes',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'sort' => 'nearest',
            'perPage' => 10,
        ]))
            ->assertOk()
            ->json('data');
        $dishDistances = collect($dishes)->pluck('distanceKm')->filter(fn ($distance): bool => $distance !== null)->map(fn ($distance): float => (float) $distance)->all();
        $sortedDishDistances = $dishDistances;
        sort($sortedDishDistances);
        self::assertSame($sortedDishDistances, $dishDistances, 'Nearest dishes should be sorted by their merchant distance.');
        self::assertTrue(collect($dishDistances)->every(fn (float $distance): bool => $distance >= 0 && $distance <= 20037.6));

        $this->getJson('/api/v2/user/restaurants/discovery?latitude=90.01&longitude=37.13')
            ->assertUnprocessable()->assertJsonValidationErrors(['latitude']);
        $this->getJson('/api/v2/user/restaurants/discovery?latitude=36.20&longitude=-180.01')
            ->assertUnprocessable()->assertJsonValidationErrors(['longitude']);
    }

    public function test_mysql_json_selection_key_order_does_not_invalidate_quote_but_values_and_types_remain_strict(): void
    {
        $selection = [
            'fulfillmentType' => 'pickup',
            'receiveMode' => 'immediate',
            'scheduledAt' => null,
            'couponCode' => null,
            'note' => 'mysql-json-key-order-regression',
            'addressId' => 7,
        ];
        $quotes = app(CommerceCheckoutQuoteService::class);
        DB::beginTransaction();

        try {
            $buyer = User::factory()->create(['email' => 'v2-quote-'.\Illuminate\Support\Str::uuid().'@example.test']);
            app(\Modules\User\Services\UserRestaurantCartService::class)->addItem((int) $buyer->id, 13, 1, [1, 4]);
            $cart = Cart::query()->where('user_id', $buyer->id)->firstOrFail();
            $preview = app(\Modules\User\Services\UserRestaurantCheckoutPipelineService::class)->preview((int) $buyer->id, (int) $cart->id, 'pickup', 'immediate', null, null, $selection['note']);
            $issued = $quotes->issue((int) $cart->user_id, 'restaurant', (int) $cart->id, $selection, $preview['amounts']);
            $storedSelection = json_decode((string) DB::table('commerce_checkout_quotes')->where('id', $issued['quoteId'])->value('selection'), true, 512, JSON_THROW_ON_ERROR);

            self::assertNotSame(array_keys($selection), array_keys($storedSelection), 'MySQL should return JSON object keys in its normalized order.');
            $quotes->assertCurrent((int) $cart->user_id, 'restaurant', (int) $cart->id, $issued['quoteId'], $selection);

            foreach ([
                [...$selection, 'note' => 'changed-note'],
                [...$selection, 'addressId' => '7'],
            ] as $changedSelection) {
                try {
                    $quotes->assertCurrent((int) $cart->user_id, 'restaurant', (int) $cart->id, $issued['quoteId'], $changedSelection);
                    self::fail('Changed quote selection values or types must remain rejected.');
                } catch (ConflictHttpException $exception) {
                    self::assertSame('QUOTE_CHANGED', $exception->getMessage());
                }
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_public_list_fields_are_allowlisted_and_pagination_is_stable_and_bounded(): void
    {
        $first = $this->getJson('/api/v2/user/restaurants/discovery?perPage=1')->assertOk();
        $repeat = $this->getJson('/api/v2/user/restaurants/discovery?perPage=1')->assertOk();
        self::assertSame($first->json('data.0.id'), $repeat->json('data.0.id'));

        $this->assertNoPrivateKeys($first->json('data'));
        $this->getJson('/api/v2/user/restaurants/discovery?perPage=51')->assertUnprocessable()->assertJsonValidationErrors(['perPage']);
    }

    public function test_optional_sanctum_identity_keeps_public_read_access_and_personalization_boolean_only(): void
    {
        Sanctum::actingAs(new User(['id' => 987654321]));

        $response = $this->getJson('/api/v2/user/restaurants/discovery?perPage=1')->assertOk();
        self::assertIsBool($response->json('data.0.isFavoritedByUser'));
        $this->assertNoPrivateKeys($response->json('data'));
    }

    public function test_availability_handles_market_timezone_overnight_bounds_unknown_and_closures(): void
    {
        config(['restaurant_v2.timezone' => 'Asia/Damascus']);
        $restaurant = new Restaurant(['is_active' => true, 'is_temporarily_closed' => false]);
        $sunday = new OperatingHour(['day_of_week' => 'sunday', 'open_time' => '20:00:00', 'close_time' => '02:00:00', 'is_closed' => false]);
        $restaurant->setRelation('operatingHours', collect([$sunday]));

        // 23:30 UTC on Sunday is 02:30 Monday in Damascus, after Sunday's close.
        self::assertFalse(RestaurantV2Availability::isOpen($restaurant, CarbonImmutable::parse('2026-06-14T23:30:00+00:00')));
        // Before midnight local time, Sunday’s overnight interval remains open.
        self::assertTrue(RestaurantV2Availability::isOpen($restaurant, CarbonImmutable::parse('2026-06-14T20:30:00+00:00')));

        $monday = new OperatingHour(['day_of_week' => 'monday', 'open_time' => '08:00:00', 'close_time' => '18:00:00', 'is_closed' => false]);
        $restaurant->setRelation('operatingHours', collect([$monday]));
        self::assertTrue(RestaurantV2Availability::isOpen($restaurant, CarbonImmutable::parse('2026-06-15T05:00:00+00:00'))); // 08:00 inclusive
        self::assertFalse(RestaurantV2Availability::isOpen($restaurant, CarbonImmutable::parse('2026-06-15T15:00:00+00:00'))); // 18:00 exclusive

        $restaurant->setRelation('operatingHours', collect());
        self::assertNull(RestaurantV2Availability::isOpen($restaurant, CarbonImmutable::parse('2026-06-15T10:00:00+03:00')));
        $broken = new OperatingHour(['day_of_week' => 'monday', 'open_time' => null, 'close_time' => null, 'is_closed' => false]);
        $restaurant->setRelation('operatingHours', collect([$broken]));
        self::assertNull(RestaurantV2Availability::isOpen($restaurant, CarbonImmutable::parse('2026-06-15T10:00:00+03:00')));

        $restaurant->setRelation('operatingHours', collect([$monday]));
        $restaurant->is_temporarily_closed = true;
        self::assertFalse(RestaurantV2Availability::isOpen($restaurant, CarbonImmutable::parse('2026-06-15T10:00:00+03:00')));
        $restaurant->is_temporarily_closed = false;
        $restaurant->suspension_until = '2026-06-15 11:00:00';
        self::assertFalse(RestaurantV2Availability::isOpen($restaurant, CarbonImmutable::parse('2026-06-15T10:00:00+03:00')));
    }

    /** @param array<mixed> $value */
    private function assertNoPrivateKeys(array $value): void
    {
        $blocked = ['email', 'phone', 'user_id', 'owner', 'reputation_score', 'visibility_score', 'warning_count', 'suspension_until', 'password'];
        foreach ($value as $key => $child) {
            self::assertNotContains((string) $key, $blocked, 'Private/internal field leaked into V2 output.');
            if (is_array($child)) {
                $this->assertNoPrivateKeys($child);
            }
        }
    }
}
