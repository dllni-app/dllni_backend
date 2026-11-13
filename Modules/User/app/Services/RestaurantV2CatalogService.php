<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\Review;
use Modules\Resturants\Support\RestaurantPromotionCapability;
use Modules\User\Http\Resources\RestaurantV2ProductResource;
use Modules\User\Http\Resources\RestaurantV2RestaurantResource;

final class RestaurantV2CatalogService
{
    /** @return array<string, mixed> */
    public function home(Request $request, int $limit): array
    {
        $page = (int) $request->input('page', 1);
        $sections = [];
        foreach (['promotions', 'cuisines', 'restaurants', 'dishes'] as $section) {
            $sections[$section] = $this->homeSection($request, $section, $limit, $page);
        }
        // Additive V2 feeds. The original four section names remain stable.
        $sections['foodCategories'] = $this->homeSection($request, 'food-categories', $limit, $page);
        $sections['popularMeals'] = $this->homeSection($request, 'popular-meals', $limit, $page);

        return ['sections' => $sections];
    }

    /** @return array<string, mixed> */
    public function homeSection(Request $request, string $section, int $perPage, int $page): array
    {
        // Only home cards use thumbnails; detail contracts retain larger images.
        $previous = $request->attributes->get('restaurant_home_thumbnail');
        $request->attributes->set('restaurant_home_thumbnail', true);
        try {
            return match ($section) {
                'promotions' => $this->homeOffers($perPage, $page, $request->boolean('includeUpcoming')),
                'cuisines' => ['items' => $this->cuisineItems()],
                'restaurants' => $this->homeRestaurants($request, $perPage, $page),
                'dishes' => $this->homeProducts($request, $perPage, $page),
                'food-categories' => ['items' => $this->foodCategories()['data']],
                'popular-meals' => $this->homePopularMeals($request, $perPage, $page),
                default => ['items' => []],
            };
        } finally {
            $request->attributes->set('restaurant_home_thumbnail', $previous);
        }
    }

    /** @return array<string, mixed> */
    public function discovery(Request $request, array $filters, int $perPage, int $page): array
    {
        $restaurants = $this->applyRestaurantFilters($this->restaurantQuery($request), $filters, $request)
            ->paginate(perPage: $perPage, page: $page);

        $this->attachRestaurantFavorites($restaurants->getCollection(), $request);

        return $this->pageData($restaurants, fn (Restaurant $item): array => $this->restaurantData($item, $request));
    }

    /** @return array<string, mixed> */
    public function search(Request $request, string $text, string $scope, array $filters, int $perPage, int $restaurantPage, int $dishPage): array
    {
        $filters['browseEligible'] = $text === '';
        $escaped = addcslashes($text, '%_\\');
        $normalizedText = RestaurantFoodSearchNormalizer::normalize($text);
        $normalizedPattern = RestaurantFoodSearchNormalizer::likePattern($text);
        $normalizedPrefix = RestaurantFoodSearchNormalizer::likePattern($text, true);
        $result = [];

        if (isset($filters['restaurantId'])) {
            Restaurant::query()->where('is_active', true)->findOrFail($filters['restaurantId']);
        }

        if ($scope !== 'dishes') {
            $restaurants = $this->applyRestaurantFilters(
                $this->restaurantQuery($request)->when($text !== '', fn (Builder $restaurants): Builder => $restaurants->where(function (Builder $query) use ($escaped, $normalizedText, $normalizedPattern, $filters): void {
                    $query->where('restaurants.name', 'like', "%{$escaped}%")
                        ->orWhere('restaurants.description', 'like', "%{$escaped}%")
                        ->orWhere('restaurants.city', 'like', "%{$escaped}%")
                        ->orWhere('restaurants.district', 'like', "%{$escaped}%");
                    if ($normalizedText !== '') {
                        $query->orWhereRaw(RestaurantFoodSearchNormalizer::sql('restaurants.name').' LIKE ?', [$normalizedPattern]);
                    }
                    {
                        $query->orWhereHas('products', function (Builder $product) use ($filters, $escaped, $normalizedText, $normalizedPattern): void {
                            $this->matchingRestaurantProducts($product, $filters);
                            $product->where(function (Builder $match) use ($escaped, $normalizedText, $normalizedPattern): void {
                                $match->where('products.name', 'like', "%{$escaped}%")
                                    ->orWhere('products.description', 'like', "%{$escaped}%")
                                    ->orWhere('products.search_tags', 'like', "%{$escaped}%")
                                    ->orWhereHas('category', fn (Builder $category): Builder => $category->where('name', 'like', "%{$escaped}%"));
                                if ($normalizedText !== '') {
                                    $match->orWhereRaw(RestaurantFoodSearchNormalizer::sql('products.name').' LIKE ?', [$normalizedPattern]);
                                }
                            });
                        });
                    }
                })),
                $filters,
                $request,
            );
            $sort = (string) ($filters['sort'] ?? 'relevance');
            if ($sort === 'relevance' && $text !== '') {
                $restaurants->reorder()
                    ->orderByRaw(
                        'CASE WHEN '.RestaurantFoodSearchNormalizer::sql('restaurants.name').' = ? THEN 0 WHEN '.
                        RestaurantFoodSearchNormalizer::sql('restaurants.name').' LIKE ? THEN 1 ELSE 2 END',
                        [$normalizedText, $normalizedPrefix],
                    )
                    ->orderByDesc('restaurants.is_featured')
                    ->orderByRaw('CASE WHEN reviews_avg_rating IS NULL THEN 1 ELSE 0 END')
                    ->orderByDesc('reviews_avg_rating')
                    ->orderBy('restaurants.id');
            }
            $restaurants = $restaurants->paginate(perPage: $perPage, page: $restaurantPage);

            $this->attachRestaurantFavorites($restaurants->getCollection(), $request);
            $result['restaurants'] = $this->pageData($restaurants, fn (Restaurant $item): array => $this->restaurantData($item, $request));
        }

        if ($scope !== 'restaurants') {
            $products = $this->productQuery($request)
                ->when($text !== '', fn (Builder $products): Builder => $products->where(function (Builder $query) use ($escaped, $normalizedText, $normalizedPattern): void {
                    $query->where('products.name', 'like', "%{$escaped}%")
                        ->orWhere('products.description', 'like', "%{$escaped}%")
                        ->orWhere('products.search_tags', 'like', "%{$escaped}%")
                        ->orWhereHas('category', fn (Builder $category): Builder => $category->where('name', 'like', "%{$escaped}%"));
                    if ($normalizedText !== '') {
                        $query->orWhereRaw(RestaurantFoodSearchNormalizer::sql('products.name').' LIKE ?', [$normalizedPattern]);
                    }
                }));

            $this->applySmartConstraints($products, $filters);
            if (isset($filters['restaurantId'])) {
                $products->where('products.restaurant_id', $filters['restaurantId']);
            }
            if (isset($filters['categoryId'])) {
                $products->where('products.category_id', $filters['categoryId']);
            }
            if (isset($filters['foodCategoryId'])) {
                $products->where('products.primary_food_category_id', $filters['foodCategoryId'])->where('products.classification_status', 'reviewed');
            }
            if (isset($filters['cuisineId'])) {
                $products->whereHas('restaurant.cuisineTypes', fn (Builder $cuisine): Builder => $cuisine->where('cuisine_types.id', $filters['cuisineId']));
            }
            if (($filters['hasOffers'] ?? false) === true) {
                $products->whereHas('offers', fn (Builder $offer): Builder => $this->eligibleOffers($offer));
            }
            if (($filters['openNow'] ?? false) === true) {
                $products->whereHas('restaurant', fn (Builder $merchant): Builder => RestaurantV2Availability::constrainOpen($merchant));
            }

            if (isset($filters['area'])) {
                $area = '%'.addcslashes($filters['area'], '%_\\').'%';
                $products->whereHas('restaurant', fn (Builder $restaurant): Builder => $restaurant->where(fn (Builder $location) => $location->where('city', 'like', $area)->orWhere('district', 'like', $area)));
            }
            foreach (['minPrice' => '>=', 'maxPrice' => '<='] as $key => $operator) {
                if (isset($filters[$key])) {
                    $products->whereRaw($this->effectivePriceSql().' '.$operator.' ?', [...$this->effectivePriceBindings(), $filters[$key]]);
                }
            }
            $sort = (string) ($filters['sort'] ?? 'relevance');
            if ($sort === 'relevance' && $text !== '') {
                $products->orderByRaw(
                    'CASE WHEN '.RestaurantFoodSearchNormalizer::sql('products.name').' = ? THEN 0 WHEN '.
                    RestaurantFoodSearchNormalizer::sql('products.name').' LIKE ? THEN 1 ELSE 2 END',
                    [$normalizedText, $normalizedPrefix],
                )
                    ->orderByDesc('products.is_featured')->orderBy('products.name')->orderBy('products.id');
            } else {
                if ($sort === 'relevance' && $text === '' && ! isset($filters['foodCategoryId']) && ! isset($filters['categoryId']) && ! array_key_exists('candidateIds', $filters)) {
                    $sales = \Modules\Resturants\Models\OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
                        ->whereIn('orders.status', [OrderStatus::Completed->value, OrderStatus::PickedUp->value])
                        ->where('orders.created_at', '>=', now()->subDays(30))
                        ->selectRaw('order_items.product_id, SUM(order_items.quantity) as units')->groupBy('order_items.product_id');
                    $products->select('products.*')->leftJoinSub($sales, 'search_sales', 'search_sales.product_id', '=', 'products.id')
                        ->orderByRaw("CASE WHEN products.product_kind IN ('main_dish', 'combo_meal') AND products.classification_status = 'reviewed' THEN 0 ELSE 1 END")
                        ->orderByDesc('search_sales.units');
                }
                if ($sort === 'relevance' && !empty($filters['candidateIds'])) {
                    $ids = array_values($filters['candidateIds']);
                    $products->orderByRaw('FIELD(products.id, '.implode(',', array_fill(0, count($ids), '?')).')', $ids);
                }
                $this->sortProducts($products, $sort, $request);
            }
            $paginator = $products->paginate(perPage: $perPage, page: $dishPage);
            $this->attachProductFavorites($paginator->getCollection(), $request);
            $result['dishes'] = $this->pageData($paginator, fn (Product $item): array => $this->productData($item, $request));
        }

        if ($scope !== 'all') {
            return $result[$scope];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function suggestions(?string $text, int $limit): array
    {
        $escaped = $text !== null ? addcslashes($text, '%_\\') : null;
        $normalized = $text !== null ? RestaurantFoodSearchNormalizer::normalize($text) : '';
        $normalizedPattern = $text !== null ? RestaurantFoodSearchNormalizer::likePattern($text) : '';
        $suggestions = collect();

        $restaurants = Restaurant::query()->where('is_active', true)->with('media')->orderByDesc('is_featured');
        if ($escaped !== null) {
            $restaurants->where(function (Builder $query) use ($escaped, $normalized, $normalizedPattern): void {
                $query->where('restaurants.name', 'like', "%{$escaped}%");
                if ($normalized !== '') {
                    $query->orWhereRaw(RestaurantFoodSearchNormalizer::sql('restaurants.name').' LIKE ?', [$normalizedPattern]);
                }
            });
        }
        foreach ($restaurants->orderBy('name')->orderBy('id')->limit($limit)->get() as $restaurant) {
            $suggestions->push([
                'type' => 'restaurant', 'id' => (int) $restaurant->id, 'label' => $restaurant->name,
                'subtitle' => $restaurant->city, 'imageUrl' => ($image = $restaurant->getFirstMediaUrl('primary-image')) !== '' ? $image : null,
            ]);
        }

        if ($suggestions->count() < $limit) {
            $products = $this->availableProducts(Product::query())
                ->whereHas('restaurant', fn (Builder $q): Builder => $q->where('is_active', true))
                ->with(['media', 'restaurant:id,name']);
            if ($escaped !== null) {
                $products->where(function (Builder $query) use ($escaped, $normalized, $normalizedPattern): void {
                    $query->where('products.name', 'like', "%{$escaped}%");
                    if ($normalized !== '') {
                        $query->orWhereRaw(RestaurantFoodSearchNormalizer::sql('products.name').' LIKE ?', [$normalizedPattern]);
                    }
                });
            }
            foreach ($products->orderByDesc('is_featured')->orderBy('name')->orderBy('id')->limit($limit - $suggestions->count())->get() as $product) {
                $suggestions->push([
                    'type' => 'dish', 'id' => (int) $product->id, 'label' => $product->name,
                    'subtitle' => $product->restaurant?->name,
                    'imageUrl' => ($image = $product->getFirstMediaUrl('primary-image')) !== '' ? $image : null,
                ]);
            }
        }

        return ['data' => $suggestions->values()->all()];
    }

    /** @return array<string, mixed> */
    public function cuisines(): array
    {
        return ['data' => CuisineType::query()
            ->whereHas('restaurants', fn (Builder $q): Builder => $q->where('restaurants.is_active', true)->where(fn (Builder $active) => $active->whereNull('restaurants.suspension_until')->orWhere('restaurants.suspension_until', '<=', now())))
            ->orderBy('name')->orderBy('id')->get(['cuisine_types.id', 'name', 'slug'])
            ->map(fn (CuisineType $cuisine): array => ['id' => (int) $cuisine->id, 'name' => $cuisine->name, 'slug' => $cuisine->slug, 'imageUrl' => $cuisine->iconUrl()])->all()];
    }

    /** @return array<string, mixed> */
    public function categories(): array
    {
        return ['data' => Category::query()
            ->whereHas('restaurant', fn (Builder $q): Builder => $q->where('is_active', true))
            ->whereHas('products', fn (Builder $q): Builder => $this->availableProducts($q))
            ->with('media')
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()
            ->map(function (Category $category): array {
                $image = \App\Support\ImageVariants::url($category, 'category-image', true);

                return ['id' => (int) $category->id, 'name' => $category->name, 'slug' => $category->slug,
                    'sortOrder' => (int) $category->sort_order, 'imageUrl' => $image !== '' ? $image : null];
            })->all()];
    }

    /** @return array<string, mixed> */
    public function foodCategories(): array
    {
        $eligibleCounts = RestaurantFoodEligibility::browsableProducts(Product::query())
            ->where('products.classification_status', 'reviewed')
            ->whereNotNull('products.primary_food_category_id')
            ->whereHas('restaurant', fn (Builder $restaurant): Builder => $restaurant->where('restaurants.is_active', true)->where(fn (Builder $active) => $active->whereNull('restaurants.suspension_until')->orWhere('restaurants.suspension_until', '<=', now())))
            ->selectRaw('products.primary_food_category_id, COUNT(*) as eligible_products_count')
            ->groupBy('products.primary_food_category_id');

        return ['data' => FoodDiscoveryCategory::query()
            ->joinSub($eligibleCounts, 'eligible_food', 'eligible_food.primary_food_category_id', '=', 'food_discovery_categories.id')
            ->where('food_discovery_categories.is_active', true)
            ->select('food_discovery_categories.*', 'eligible_food.eligible_products_count')
            ->with('media')
            ->orderBy('food_discovery_categories.sort_order')->orderBy('food_discovery_categories.id')->get()
            ->map(function (FoodDiscoveryCategory $category): array {
                $image = \App\Support\ImageVariants::url($category, 'category-image', true);

                return [
                    'id' => (int) $category->id,
                    'slug' => $category->slug,
                    'name' => $category->localizedName((string) app()->getLocale()),
                    'imageUrl' => $image !== '' ? $image : null,
                    'eligibleProductCount' => (int) $category->eligible_products_count,
                ];
            })->all()];
    }

    /** @return array<string, mixed> */
    public function offers(int $perPage, int $page, bool $thumbnail = false, bool $upcoming = false): array
    {
        $now = CarbonImmutable::now();
        $paginator = RestaurantPromotionCatalog::constrain(Offer::query(), $upcoming ? 'upcoming' : 'active')
            ->whereHas('restaurant', fn (Builder $q): Builder => $q->where('is_active', true))
            ->with($this->offerCardRelations())
            ->when($upcoming, fn ($q) => $q->orderBy('starts_at'), fn ($q) => $q->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END')->orderBy('ends_at'))
            ->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);

        return $this->pageData($paginator, fn (Offer $offer): array => $this->offerData($offer, $thumbnail));
    }

    /** @return array<string, mixed> */
    public function restaurantDetail(Request $request, int $restaurantId, int $reviewsPage, int $reviewsPerPage, string $shareUrl): array
    {
        $restaurant = $this->restaurantQuery($request)
            ->with([
                'media', 'operatingHours', 'cuisineTypes',
                'offers' => fn ($q) => $this->eligibleOffers($q)->with($this->offerCardRelations(false))->orderBy('starts_at')->orderBy('id'),
                'categories' => fn ($q) => $q->withCount(['products as available_products_count' => fn (Builder $products): Builder => $this->availableProducts($products)])->orderBy('sort_order')->orderBy('id'),
                'categories.products' => fn ($q) => $this->availableProducts($q)
                    ->with($this->productCardRelations($request, false, false))
                    ->orderByDesc('is_featured')->orderBy('name')->orderBy('id')->limit(30),
            ])->findOrFail($restaurantId);

        $this->attachRestaurantFavorites(collect([$restaurant]), $request);
        $restaurant->offers->each(fn (Offer $offer) => $offer->setRelation('restaurant', $restaurant));
        $this->attachMenuParents($restaurant, $restaurant->categories);
        $products = $restaurant->categories->flatMap(fn (Category $category) => $category->products)->unique('id')->values();
        $this->attachProductFavorites($products, $request);
        $reviews = $restaurant->reviews()->with('user')->latest('id')->paginate(perPage: $reviewsPerPage, page: $reviewsPage);
        $totalReviews = $reviews->total();
        $average = $totalReviews > 0 ? (float) $restaurant->reviews_avg_rating : null;
        $counts = $restaurant->reviews()->selectRaw('rating, count(*) as aggregate')->groupBy('rating')->pluck('aggregate', 'rating');
        $gallery = $restaurant->getMedia('images')->map(fn ($media): string => $media->getUrl())->values()->all();
        $popularProducts = $this->availableProducts($restaurant->products())->with($this->productCardRelations($request, false))
            ->orderByDesc('is_featured')->orderBy('name')->orderBy('id')->limit(10)->get();
        $popularProducts->each(fn (Product $product) => $product->setRelation('restaurant', $restaurant));
        $this->attachProductFavorites($popularProducts, $request);

        return $this->restaurantData($restaurant, $request) + [
            'images' => $gallery,
            'rating' => ['average' => $average !== null && $totalReviews > 0 ? number_format($average, 2, '.', '') : null, 'total' => $totalReviews],
            'ratingSummary' => ['average' => $average !== null && $totalReviews > 0 ? number_format($average, 1, '.', '') : null, 'total' => $totalReviews,
                'counts' => array_combine(['5', '4', '3', '2', '1'], array_map(fn (int $rating): int => (int) ($counts[$rating] ?? 0), [5, 4, 3, 2, 1]))],
            'operatingHours' => $restaurant->operatingHours->map(fn ($hour): array => [
                'dayOfWeek' => $hour->day_of_week?->value ?? $hour->day_of_week,
                'openTime' => $hour->open_time, 'closeTime' => $hour->close_time, 'isClosed' => (bool) $hour->is_closed,
            ])->values()->all(),
            'offers' => $restaurant->offers->map(fn (Offer $offer): array => $this->offerData($offer))->all(),
            'categories' => $restaurant->categories->map(fn (Category $category): array => [
                'id' => (int) $category->id, 'name' => $category->name, 'sortOrder' => (int) $category->sort_order,
                'totalProducts' => (int) $category->available_products_count, 'hasMore' => (int) $category->available_products_count > $category->products->count(),
                'products' => $category->products->unique('id')->values()->map(fn (Product $product): array => $this->productData($product, $request))->all(),
            ])->filter(fn (array $category): bool => $category['products'] !== [])->values()->all(),
            'popularProducts' => $popularProducts->map(fn (Product $product): array => $this->productData($product, $request))->all(),
            'reviews' => $reviews->getCollection()->map(fn ($review): array => [
                'id' => (int) $review->id, 'rating' => (int) $review->rating, 'comment' => $review->comment,
                'createdAt' => $review->created_at?->toIso8601String(),
                'customerName' => $review->user?->name,
            ])->all(),
            'reviewsMeta' => $this->paginationMeta($reviews),
            'shareUrl' => $shareUrl,
        ];
    }

    /** @return array<string, mixed> */
    public function menu(Request $request, int $restaurantId, int $perCategory, ?int $categoryId = null, int $page = 1): array
    {
        $restaurant = Restaurant::query()->where('is_active', true)
            ->with(['media' => fn ($media) => $media->where('collection_name', 'primary-image')])->findOrFail($restaurantId);
        $baseCategories = $restaurant->categories()->whereHas('products', fn (Builder $q): Builder => $this->availableProducts($q));

        if ($categoryId !== null) {
            $category = (clone $baseCategories)->whereKey($categoryId)
                ->withCount(['products as available_products_count' => fn (Builder $q): Builder => $this->availableProducts($q)])
                ->firstOrFail();
            $paginator = $this->availableProducts($category->products())
                ->with($this->productCardRelations($request, false, false))
                ->orderByDesc('is_featured')->orderBy('name')->orderBy('id')
                ->paginate(perPage: $perCategory, page: $page);
            $paginator->getCollection()->each(fn (Product $product) => $product->setRelation('restaurant', $restaurant)->setRelation('category', $category));
            $this->attachProductFavorites($paginator->getCollection(), $request);

            return ['restaurantId' => (int) $restaurant->id, 'categories' => [[
                'id' => (int) $category->id,
                'name' => $category->name,
                'sortOrder' => (int) $category->sort_order,
                'imageUrl' => ($image = $category->getFirstMediaUrl('category-image')) !== '' ? $image : null,
                'products' => $paginator->getCollection()->map(fn (Product $product): array => $this->productData($product, $request))->all(),
                'totalProducts' => (int) $category->available_products_count,
                'hasMore' => $paginator->hasMorePages(),
            ]], 'meta' => $this->paginationMeta($paginator)];
        }

        $categories = $baseCategories
            ->orderBy('sort_order')->orderBy('id')
            ->withCount(['products as available_products_count' => fn (Builder $q): Builder => $this->availableProducts($q)])
            ->with(['media' => fn ($media) => $media->where('collection_name', 'category-image'),
                'products' => fn ($q) => $this->availableProducts($q)->limit($perCategory)
                ->with($this->productCardRelations($request, false, false))
                ->orderByDesc('is_featured')->orderBy('name')->orderBy('id')])
            ->get();
        $this->attachMenuParents($restaurant, $categories);
        $products = $categories->flatMap(fn (Category $category) => $category->products)->unique('id')->values();
        $this->attachProductFavorites($products, $request);
        $categoryData = $categories->map(function (Category $category) use ($request): array {
            $image = \App\Support\ImageVariants::url($category, 'category-image', true);

            return ['id' => (int) $category->id, 'name' => $category->name, 'sortOrder' => (int) $category->sort_order,
                'imageUrl' => $image !== '' ? $image : null,
                'products' => $category->products->unique('id')->values()
                    ->map(fn (Product $product): array => $this->productData($product, $request))->all(),
                'totalProducts' => (int) $category->available_products_count,
                'hasMore' => (int) $category->available_products_count > $category->products->count(),
                'nextPage' => (int) $category->available_products_count > $category->products->count() ? 2 : null];
        })->filter(fn (array $category): bool => $category['products'] !== [])->values()->all();

        return ['restaurantId' => (int) $restaurant->id, 'categories' => $categoryData];
    }

    /** @return array<string, mixed> */
    public function productDetail(Request $request, int $productId, string $shareUrl): array
    {
        $product = Product::query()->where('products.catalog_role', 'independent')
            ->whereHas('restaurant', fn (Builder $q): Builder => $q->where('is_active', true))
            ->with([...$this->productCardRelations($request), 'modifierGroups.modifiers'])
            ->findOrFail($productId);
        $this->attachProductFavorites(collect([$product]), $request);

        $data = $this->productData($product, $request);
        $data['modifierGroups'] = $product->modifierGroups->sortBy('id')->values()->map(fn ($group): array => [
            'id' => (int) $group->id,
            'name' => $group->name,
            'isRequired' => (bool) $group->is_required,
            'minSelections' => (int) $group->min_selections,
            'maxSelections' => (int) $group->max_selections,
            'modifiers' => $group->modifiers->sortBy('sort_order')->sortBy('id')->values()->map(fn ($modifier): array => [
                'id' => (int) $modifier->id, 'name' => $modifier->name,
                'price' => number_format((float) $modifier->price, 2, '.', ''), 'sortOrder' => (int) $modifier->sort_order,
            ])->all(),
        ])->all();
        $data['shareUrl'] = $shareUrl;
        $data['complementaryProducts'] = app(RestaurantMealComplements::class)->forProduct($product);

        return $data;
    }

    /** @return array<string, mixed> */
    private function homeOffers(int $perPage, int $page, bool $includeUpcoming = false): array
    {
        $offers = $this->offers($perPage, $page, thumbnail: true);
        $result = ['items' => $offers['data'], 'meta' => $offers['meta']];
        if ($includeUpcoming) {
            $upcoming = $this->offers($perPage, 1, thumbnail: true, upcoming: true);
            $result['upcomingItems'] = $upcoming['data'];
            $result['nextRefreshAt'] = $this->nextOfferTransition();
        }

        return $result;
    }

    /** No future offer details are exposed for start-only publication. */
    public function nextOfferTransition(): ?string
    {
        $now = CarbonImmutable::now();
        $base = Offer::query()->where('is_active', true)
            ->whereHas('restaurant', fn (Builder $q) => $q->where('is_active', true));
        $next = (clone $base)->where('starts_at', '>', $now)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereColumn('ends_at', '>', 'starts_at'))->min('starts_at');
        $end = (clone $base)->activeAt($now)->where('ends_at', '>', $now)->min('ends_at');
        $times = array_filter([$next, $end]);
        return $times ? CarbonImmutable::parse(min($times))->toIso8601String() : null;
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    private function cuisineItems(): array
    {
        return CuisineType::query()->whereHas('restaurants', fn (Builder $q): Builder => $q->where('restaurants.is_active', true))
            ->orderBy('name')->orderBy('id')->get(['cuisine_types.id', 'name', 'slug'])
            ->map(fn (CuisineType $cuisine): array => ['id' => (int) $cuisine->id, 'name' => $cuisine->name, 'slug' => $cuisine->slug, 'imageUrl' => $cuisine->iconUrl()])->all();
    }

    /** @return array<string, mixed> */
    private function homeRestaurants(Request $request, int $perPage, int $page): array
    {
        $restaurants = $this->restaurantQuery($request)->orderByDesc('restaurants.is_featured')
            ->orderByRaw('CASE WHEN reviews_avg_rating IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('reviews_avg_rating')->orderBy('restaurants.id')
            ->paginate(perPage: $perPage, page: $page);
        $this->attachRestaurantFavorites($restaurants->getCollection(), $request);

        return ['items' => $restaurants->getCollection()->map(fn (Restaurant $restaurant): array => $this->restaurantData($restaurant, $request))->all(),
            'meta' => $this->paginationMeta($restaurants)];
    }

    /** @return array<string, mixed> */
    private function homeProducts(Request $request, int $perPage, int $page): array
    {
        $products = $this->curatedMealQuery($request)
            ->orderByDesc('products.is_featured')->orderBy('products.name')->orderBy('products.id')
            ->paginate(perPage: $perPage, page: $page);
        $this->attachProductFavorites($products->getCollection(), $request);

        return ['items' => $products->getCollection()->map(fn (Product $product): array => $this->productData($product, $request))->all(),
            'meta' => $this->paginationMeta($products)];
    }

    /** @return array<string, mixed> */
    private function homePopularMeals(Request $request, int $perPage, int $page): array
    {
        $since = CarbonImmutable::now()->subDays(30);
        $sales = \Modules\Resturants\Models\OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', [OrderStatus::Completed->value, OrderStatus::PickedUp->value])
            ->where('orders.created_at', '>=', $since)
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as completed_units_30d')
            ->groupBy('order_items.product_id');

        // Rank lightweight candidates, then hydrate only the visible page.
        $base = $this->curatedMealQuery($request);
        $candidates = (clone $base)->setEagerLoads([])
            ->joinSub($sales, 'recent_sales', 'recent_sales.product_id', '=', 'products.id')
            ->select('products.id', 'products.restaurant_id', 'products.primary_food_category_id', 'recent_sales.completed_units_30d')
            ->orderByDesc('completed_units_30d')->orderBy('products.id')
            ->limit(240)->get();

        $diversified = RestaurantPopularMealRanker::diversify($candidates);
        $ids = $diversified->slice(($page - 1) * $perPage, $perPage)->pluck('id');
        $hydrated = $ids->isEmpty() ? collect() : (clone $base)->whereIn('products.id', $ids)->get()->keyBy('id');
        $pageItems = $ids->map(fn ($id) => $hydrated->get($id))->filter()->values();
        $this->attachProductFavorites($pageItems, $request);
        $paginator = new LengthAwarePaginator(
            $pageItems, $diversified->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return [
            'items' => $pageItems->map(
                fn (Product $product): array => $this->productData($product, $request)
            )->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    private function curatedMealQuery(Request $request): Builder
    {
        return $this->productQuery($request)
            ->where('products.classification_status', 'reviewed')
            ->whereIn('products.product_kind', ['main_dish', 'combo_meal'])
            ->whereNotNull('products.primary_food_category_id');
    }

    private function restaurantQuery(Request $request): Builder
    {
        $query = Restaurant::query()->select('restaurants.*')->where('restaurants.is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('restaurants.suspension_until')->orWhere('restaurants.suspension_until', '<=', now()))
            ->withAvg('reviews', 'rating')->withCount('reviews')
            ->with(['media', 'cuisineTypes', 'operatingHours', 'offers' => fn ($q) => $this->eligibleOffers($q)
                ->orderByDesc('starts_at')->orderByDesc('id')->limit(1)]);

        if (($coordinates = $this->requestCoordinates($request)) !== null) {
            $query->selectRaw(
                '(6371 * acos(least(1, greatest(-1, cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))))) as distanceKm',
                [$coordinates['latitude'], $coordinates['longitude'], $coordinates['latitude']]
            )->whereNotNull('latitude')->whereNotNull('longitude');
        }

        return $query;
    }

    private function productQuery(Request $request): Builder
    {
        return $this->availableProducts(Product::query())
            ->whereHas('restaurant', fn (Builder $q): Builder => $q->where('restaurants.is_active', true)->where(fn (Builder $active) => $active->whereNull('restaurants.suspension_until')->orWhere('restaurants.suspension_until', '<=', now())))
            ->with($this->productCardRelations($request));
    }

    /** Only hydrate relationships and media collections used by product cards. */
    private function productCardRelations(Request $request, bool $includeRestaurant = true, bool $includeCategory = true): array
    {
        $relations = [
            'media' => fn ($media) => $media->whereIn('collection_name', ['primary-image', 'images']),
            'foodCategory',
            'offers' => function ($offers) use ($request) {
                $offers->activeAt();
                if (RestaurantPromotionCapability::supported($request)) {
                    $offers->with(['rewardProducts' => fn ($products) => $products->select([
                        'products.id', 'products.restaurant_id', 'products.catalog_role',
                        'products.is_available', 'products.unavailable_until',
                    ])]);
                } else {
                    $offers->where(fn (Builder $q) => $q->whereNull('offers.offer_type')->orWhere('offers.offer_type', 'product_discount'));
                }
            },
        ];
        if ($includeRestaurant) {
            $relations['restaurant'] = fn ($restaurant) => $restaurant->select(['id', 'name']);
            $relations['restaurant.media'] = fn ($media) => $media->where('collection_name', 'primary-image');
        }
        if ($includeCategory) {
            $relations[] = 'category:id,name';
        }

        return $relations;
    }

    /** Reuse already-loaded parents rather than querying each inverse relation. */
    private function attachMenuParents(Restaurant $restaurant, Collection $categories): void
    {
        foreach ($categories as $category) {
            foreach ($category->products as $product) {
                $product->setRelation('restaurant', $restaurant)->setRelation('category', $category);
            }
        }
    }

    private function offerCardRelations(bool $includeRestaurant = true): array
    {
        $columns = ['products.id', 'products.restaurant_id', 'products.name', 'products.price',
            'products.catalog_role', 'products.is_available', 'products.unavailable_until'];
        $relations = [
            'products' => fn ($products) => $products->select($columns),
            'products.media' => fn ($media) => $media->where('collection_name', 'primary-image'),
            'rewardProducts' => fn ($products) => $products->select($columns),
            'rewardProducts.media' => fn ($media) => $media->where('collection_name', 'primary-image'),
        ];
        if ($includeRestaurant) {
            $relations['restaurant'] = fn ($restaurant) => $restaurant->select(['id', 'name']);
            $relations['restaurant.media'] = fn ($media) => $media->where('collection_name', 'primary-image');
        }

        return $relations;
    }

    private function applyRestaurantFilters(Builder $query, array $filters, Request $request): Builder
    {
        if (isset($filters['cuisineId'])) {
            $query->whereHas('cuisineTypes', fn (Builder $cuisine): Builder => $cuisine->where('cuisine_types.id', $filters['cuisineId']));
        }
        if (isset($filters['area'])) {
            $area = '%'.addcslashes($filters['area'], '%_\\').'%';
            $query->where(fn (Builder $location) => $location->where('restaurants.city', 'like', $area)->orWhere('restaurants.district', 'like', $area));
        }
        $productScoped = ($filters['browseEligible'] ?? false) || isset($filters['categoryId']) || isset($filters['foodCategoryId'])
            || isset($filters['minPrice']) || isset($filters['maxPrice'])
            || array_key_exists('candidateIds', $filters) || isset($filters['requiredTerm']) || isset($filters['maxPreparationMinutes']) || isset($filters['servings']) || isset($filters['ingredientExclusions'])
            || (isset($filters['cuisineId']) && ($filters['hasOffers'] ?? false) === true);
        if ($productScoped) {
            $query->whereHas('products', fn (Builder $product): Builder => $this->matchingRestaurantProducts($product, $filters));
        }
        if (($filters['openNow'] ?? false) === true) {
            RestaurantV2Availability::constrainOpen($query);
        }
        if (! $productScoped && ($filters['hasOffers'] ?? false) === true) {
            $query->whereHas('offers', fn (Builder $offer): Builder => $this->eligibleOffers($offer));
        }
        $sort = (string) ($filters['sort'] ?? 'rating');
        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $cheapest = $this->matchingRestaurantProducts(Product::query(), $filters)
                ->whereColumn('products.restaurant_id', 'restaurants.id')
                ->selectRaw('MIN('.$this->effectivePriceSql().')', $this->effectivePriceBindings());
            $query->orderBy($cheapest, $sort === 'price_asc' ? 'asc' : 'desc')->orderBy('restaurants.id');
        } else {
            $this->sortRestaurants($query, $sort, $request);
        }

        return $query;
    }

    /** A restaurant qualifies through the same available, reviewed products as the dish results. */
    private function matchingRestaurantProducts(Builder $product, array $filters): Builder
    {
        $this->availableProducts($product);
        $this->applySmartConstraints($product, $filters);
        if (isset($filters['categoryId'])) {
            $product->where('products.category_id', $filters['categoryId']);
        }
        if (isset($filters['foodCategoryId'])) {
            $product->where('products.primary_food_category_id', $filters['foodCategoryId'])
                ->where('products.classification_status', 'reviewed');
        }
        foreach (['minPrice' => '>=', 'maxPrice' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $product->whereRaw($this->effectivePriceSql().' '.$operator.' ?', [...$this->effectivePriceBindings(), $filters[$key]]);
            }
        }
        if (($filters['hasOffers'] ?? false) === true) {
            $product->whereHas('offers', fn (Builder $offer): Builder => $this->eligibleOffers($offer));
        }

        return $product;
    }

    private function applySmartConstraints(Builder $product, array $filters): void
    {
        if (array_key_exists('candidateIds', $filters)) {
            $product->whereIn('products.id', $filters['candidateIds']);
        }
        if (isset($filters['requiredTerm'])) {
            $pattern = RestaurantFoodSearchNormalizer::likePattern($filters['requiredTerm']);
            $product->where(fn (Builder $q) => $q->whereRaw(RestaurantFoodSearchNormalizer::sql('products.name').' LIKE ?', [$pattern])->orWhereRaw(RestaurantFoodSearchNormalizer::sql('products.description').' LIKE ?', [$pattern])->orWhere('products.search_tags', 'like', $pattern));
        }
        if (isset($filters['servings'])) {
            $product->where('products.servings', '>=', $filters['servings']);
        }
        if (isset($filters['ingredientExclusions'])) {
            $product->where('products.classification_status', 'reviewed');
            foreach ($filters['ingredientExclusions'] as $term) {
                $product->whereJsonContains('products.ingredient_exclusions', $term);
            }
        }
        if (isset($filters['maxPreparationMinutes'])) {
            $product->whereNotNull('products.preparation_time')->where('products.preparation_time', '<=', $filters['maxPreparationMinutes']);
        }
    }

    private function sortRestaurants(Builder $query, string $sort, Request $request): void
    {
        match ($sort) {
            'nearest' => $this->sortByDistance($query, $request),
            'fastest' => $query->orderByRaw('CASE WHEN COALESCE(estimated_preparation_time_max, estimated_preparation_time) IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('COALESCE(estimated_preparation_time_max, estimated_preparation_time)')
                ->orderBy('restaurants.id'),
            'relevance' => $query->orderByDesc('restaurants.is_featured')->orderByDesc('reviews_avg_rating')->orderBy('restaurants.id'),
            default => $query->orderByRaw('CASE WHEN reviews_avg_rating IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('reviews_avg_rating')->orderBy('restaurants.id'),
        };
    }

    private function sortByDistance(Builder $query, Request $request): void
    {
        if ($this->requestCoordinates($request) === null) {
            $query->orderByRaw('CASE WHEN reviews_avg_rating IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('reviews_avg_rating')->orderBy('restaurants.id');

            return;
        }

        $query->orderBy('distanceKm')->orderBy('restaurants.id');
    }

    /** Same current, unconditional discounts as Product::effectivePrice; conditional rewards are excluded. */
    private function effectivePriceSql(): string
    {
        return "ROUND(GREATEST(0, LEAST(products.price, COALESCE(products.discounted_price, products.price), COALESCE((SELECT MIN(CASE WHEN offers.discount_type = 'percentage' THEN products.price * (1 - LEAST(100, GREATEST(0, offers.discount_value)) / 100) ELSE products.price - GREATEST(0, offers.discount_value) END) FROM offers JOIN offer_product ON offers.id = offer_product.offer_id WHERE offer_product.product_id = products.id AND offers.is_active = 1 AND (offers.offer_type IS NULL OR offers.offer_type = 'product_discount') AND (offers.starts_at IS NULL OR offers.starts_at <= ?) AND (offers.ends_at IS NULL OR offers.ends_at > ?)), products.price))), 2)";
    }

    private function effectivePriceBindings(): array
    {
        $at = now();
        return [$at, $at];
    }

    private function sortProducts(Builder $query, string $sort, Request $request): void
    {
        if ($sort === 'nearest' && $this->requestCoordinates($request) !== null) {
            $coordinates = $this->requestCoordinates($request);
            $query->select('products.*')->selectRaw(
                '(6371 * acos(least(1, greatest(-1, cos(radians(?)) * cos(radians(restaurants.latitude)) * cos(radians(restaurants.longitude) - radians(?)) + sin(radians(?)) * sin(radians(restaurants.latitude)))))) as distanceKm',
                [$coordinates['latitude'], $coordinates['longitude'], $coordinates['latitude']]
            )->join('restaurants', 'restaurants.id', '=', 'products.restaurant_id')->whereNotNull('restaurants.latitude')->whereNotNull('restaurants.longitude')
                ->orderBy('distanceKm')->orderBy('products.id');

            return;
        }

        match ($sort) {
            'rating' => $query->orderByDesc(Review::query()->selectRaw('AVG(rating)')
                ->whereColumn('reviews.restaurant_id', 'products.restaurant_id'))->orderBy('products.id'),
            'price_asc' => $query->orderByRaw($this->effectivePriceSql().' ASC', $this->effectivePriceBindings())->orderBy('products.id'),
            'price_desc' => $query->orderByRaw($this->effectivePriceSql().' DESC', $this->effectivePriceBindings())->orderBy('products.id'),
            'fastest' => $query->orderByRaw('CASE WHEN preparation_time IS NULL THEN 1 ELSE 0 END')->orderBy('preparation_time')->orderBy('products.id'),
            default => $query->orderByDesc('products.is_featured')->orderBy('products.name')->orderBy('products.id'),
        };
    }

    private function attachRestaurantFavorites(Collection $restaurants, Request $request): void
    {
        $ids = $restaurants->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $favoriteIds = $this->favoriteIds($request, Restaurant::class, $ids);
        $restaurants->each(fn (Restaurant $item) => $item->setAttribute('isFavoritedByUser', isset($favoriteIds[(int) $item->id])));
    }

    private function attachProductFavorites(Collection $products, Request $request): void
    {
        $ids = $products->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $favoriteIds = $this->favoriteIds($request, Product::class, $ids);
        $products->each(fn (Product $item) => $item->setAttribute('isFavoritedByUser', isset($favoriteIds[(int) $item->id])));
    }

    /** @param list<int> $ids @return array<int, true> */
    private function favoriteIds(Request $request, string $type, array $ids): array
    {
        $user = $request->user('sanctum');
        if ($user === null || $ids === []) {
            return [];
        }

        return array_fill_keys(Favorite::query()->where('user_id', $user->getAuthIdentifier())
            ->where('favorable_type', $type)->whereIn('favorable_id', $ids)
            ->pluck('favorable_id')->map(fn ($id): int => (int) $id)->all(), true);
    }

    private function restaurantData(Restaurant $restaurant, Request $request): array
    {
        return RestaurantV2RestaurantResource::make($restaurant)->resolve($request);
    }

    private function productData(Product $product, Request $request): array
    {
        return RestaurantV2ProductResource::make($product)->resolve($request);
    }

    /** @param LengthAwarePaginator<mixed> $paginator @return array<string, mixed> */
    private function pageData(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => $paginator->getCollection()->map($map)->values()->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /** @param LengthAwarePaginator<mixed> $paginator @return array{currentPage:int,perPage:int,lastPage:int,total:int} */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return ['currentPage' => $paginator->currentPage(), 'perPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(), 'total' => $paginator->total()];
    }

    public function offerDetail(int $id, bool $includeUpcoming = false): array
    {
        $offer = RestaurantPromotionCatalog::constrain(Offer::query(), $includeUpcoming ? 'visible' : 'active')->whereHas('restaurant', fn (Builder $q) => $q->where('is_active', true))
            ->with($this->offerCardRelations())->findOrFail($id);

        return $this->offerData($offer);
    }

    private function offerData(Offer $offer, bool $thumbnail = false): array
    {
        $restaurant = $offer->getRelationValue('restaurant');

        return [
            'id' => (int) $offer->id, 'restaurantId' => (int) $offer->restaurant_id,
            'restaurantName' => $restaurant?->name,
            'restaurantImageUrl' => $restaurant !== null ? \App\Support\ImageVariants::url($restaurant, 'primary-image', true) : null,
            'title' => $offer->name, 'description' => $offer->description,
            'discountType' => $offer->discount_type?->value ?? $offer->discount_type,
            'discountValue' => $offer->discount_value !== null ? number_format((float) $offer->discount_value, 2, '.', '') : null,
            'startsAt' => $offer->starts_at?->toIso8601String(), 'endsAt' => $offer->ends_at?->toIso8601String(),
            ...RestaurantPromotionCatalog::details($offer, $thumbnail),
        ];
    }

    private function eligibleOffers(Builder|Relation $query): Builder|Relation
    {
        $now = CarbonImmutable::now();

        return RestaurantPromotionCatalog::constrain($query);
    }

    private function availableProducts(Builder|Relation $query): Builder|Relation
    {
        return RestaurantFoodEligibility::browsableProducts($query);
    }

    /** @return array{latitude: float,longitude: float}|null */
    private function requestCoordinates(Request $request): ?array
    {
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }
}