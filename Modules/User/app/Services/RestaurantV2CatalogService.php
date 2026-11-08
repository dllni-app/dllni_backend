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
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\Review;
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

        return ['sections' => $sections];
    }

    /** @return array<string, mixed> */
    public function homeSection(Request $request, string $section, int $perPage, int $page): array
    {
        return match ($section) {
            'promotions' => $this->homeOffers($perPage, $page),
            'cuisines' => ['items' => $this->cuisineItems()],
            'restaurants' => $this->homeRestaurants($request, $perPage, $page),
            'dishes' => $this->homeProducts($request, $perPage, $page),
            default => ['items' => []],
        };
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
        $escaped = addcslashes($text, '%_\\');
        $result = [];

        if (isset($filters['restaurantId'])) {
            Restaurant::query()->where('is_active', true)->findOrFail($filters['restaurantId']);
        }

        if ($scope !== 'dishes') {
            $restaurants = $this->applyRestaurantFilters(
                $this->restaurantQuery($request)->where(function (Builder $query) use ($escaped): void {
                    $query->where('restaurants.name', 'like', "%{$escaped}%")
                        ->orWhere('restaurants.description', 'like', "%{$escaped}%")
                        ->orWhere('restaurants.city', 'like', "%{$escaped}%")
                        ->orWhere('restaurants.district', 'like', "%{$escaped}%");
                }),
                $filters,
                $request,
            );
            $sort = (string) ($filters['sort'] ?? 'relevance');
            if ($sort === 'relevance') {
                $restaurants->reorder()
                    ->orderByRaw('CASE WHEN restaurants.name = ? THEN 0 WHEN restaurants.name LIKE ? THEN 1 ELSE 2 END', [$text, $escaped.'%'])
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
                ->where(function (Builder $query) use ($escaped): void {
                    $query->where('products.name', 'like', "%{$escaped}%")
                        ->orWhere('products.description', 'like', "%{$escaped}%")
                        ->orWhere('products.search_tags', 'like', "%{$escaped}%")
                        ->orWhereHas('category', fn (Builder $category): Builder => $category->where('name', 'like', "%{$escaped}%"));
                });

            if (isset($filters['restaurantId'])) {
                $products->where('products.restaurant_id', $filters['restaurantId']);
            }
            if (isset($filters['categoryId'])) {
                $products->where('products.category_id', $filters['categoryId']);
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

            $sort = (string) ($filters['sort'] ?? 'relevance');
            if ($sort === 'relevance') {
                $products->orderByRaw('CASE WHEN products.name = ? THEN 0 WHEN products.name LIKE ? THEN 1 ELSE 2 END', [$text, $escaped.'%'])
                    ->orderByDesc('products.is_featured')->orderBy('products.name')->orderBy('products.id');
            } else {
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
        $suggestions = collect();

        $restaurants = Restaurant::query()->where('is_active', true)->with('media')->orderByDesc('is_featured');
        if ($escaped !== null) {
            $restaurants->where('name', 'like', "%{$escaped}%");
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
                $products->where('name', 'like', "%{$escaped}%");
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
            ->whereHas('restaurants', fn (Builder $q): Builder => $q->where('restaurants.is_active', true))
            ->orderBy('name')->orderBy('id')->get(['cuisine_types.id', 'name', 'slug'])
            ->map(fn (CuisineType $cuisine): array => ['id' => (int) $cuisine->id, 'name' => $cuisine->name, 'slug' => $cuisine->slug])->all()];
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
                $image = $category->getFirstMediaUrl('category-image');

                return ['id' => (int) $category->id, 'name' => $category->name, 'slug' => $category->slug,
                    'sortOrder' => (int) $category->sort_order, 'imageUrl' => $image !== '' ? $image : null];
            })->all()];
    }

    /** @return array<string, mixed> */
    public function offers(int $perPage, int $page): array
    {
        $now = CarbonImmutable::now();
        $paginator = Offer::query()->where('is_active', true)
            ->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->whereHas('restaurant', fn (Builder $q): Builder => $q->where('is_active', true))
            ->with('restaurant.media')
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ends_at')->orderBy('id')
            ->paginate(perPage: $perPage, page: $page);

        return $this->pageData($paginator, fn (Offer $offer): array => $this->offerData($offer));
    }

    /** @return array<string, mixed> */
    public function restaurantDetail(Request $request, int $restaurantId, int $reviewsPage, int $reviewsPerPage, string $shareUrl): array
    {
        $restaurant = $this->restaurantQuery($request)
            ->with([
                'media', 'operatingHours', 'cuisineTypes',
                'offers' => fn ($q) => $this->eligibleOffers($q)->orderBy('starts_at')->orderBy('id'),
                'categories' => fn ($q) => $q->withCount(['products as available_products_count' => fn (Builder $products): Builder => $this->availableProducts($products)])->orderBy('sort_order')->orderBy('id'),
                'categories.products' => fn ($q) => $this->availableProducts($q)
                    ->with(['media', 'restaurant.media', 'category', 'offers'])
                    ->orderByDesc('is_featured')->orderBy('name')->orderBy('id')->limit(30),
            ])->findOrFail($restaurantId);

        $this->attachRestaurantFavorites(collect([$restaurant]), $request);
        $products = $restaurant->categories->flatMap(fn (Category $category) => $category->products)->unique('id')->values();
        $this->attachProductFavorites($products, $request);
        $reviews = $restaurant->reviews()->with('user')->latest('id')->paginate(perPage: $reviewsPerPage, page: $reviewsPage);
        $totalReviews = (int) $restaurant->reviews()->count();
        $average = $totalReviews > 0 ? (float) $restaurant->reviews()->avg('rating') : null;
        $counts = $restaurant->reviews()->selectRaw('rating, count(*) as aggregate')->groupBy('rating')->pluck('aggregate', 'rating');
        $gallery = $restaurant->getMedia('images')->map(fn ($media): string => $media->getUrl())->values()->all();
        $popularProducts = $this->availableProducts($restaurant->products())->with(['media', 'restaurant.media', 'category', 'offers'])
            ->orderByDesc('is_featured')->orderBy('name')->orderBy('id')->limit(10)->get();
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
        $restaurant = Restaurant::query()->where('is_active', true)->findOrFail($restaurantId);
        $baseCategories = $restaurant->categories()->whereHas('products', fn (Builder $q): Builder => $this->availableProducts($q));

        if ($categoryId !== null) {
            $category = (clone $baseCategories)->whereKey($categoryId)
                ->withCount(['products as available_products_count' => fn (Builder $q): Builder => $this->availableProducts($q)])
                ->firstOrFail();
            $paginator = $this->availableProducts($category->products())
                ->with(['media', 'restaurant.media', 'category', 'offers'])
                ->orderByDesc('is_featured')->orderBy('name')->orderBy('id')
                ->paginate(perPage: $perCategory, page: $page);
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
            ->with(['products' => fn ($q) => $this->availableProducts($q)->limit($perCategory)
                ->with(['media', 'restaurant.media', 'category', 'offers'])
                ->orderByDesc('is_featured')->orderBy('name')->orderBy('id')])
            ->get();
        $products = $categories->flatMap(fn (Category $category) => $category->products)->unique('id')->values();
        $this->attachProductFavorites($products, $request);
        $categoryData = $categories->map(function (Category $category) use ($request): array {
            $image = $category->getFirstMediaUrl('category-image');

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
        $product = Product::query()->whereHas('restaurant', fn (Builder $q): Builder => $q->where('is_active', true))
            ->with(['media', 'restaurant.media', 'restaurant.cuisineTypes', 'category', 'modifierGroups.modifiers', 'offers'])
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

        return $data;
    }

    /** @return array<string, mixed> */
    private function homeOffers(int $perPage, int $page): array
    {
        $offers = $this->offers($perPage, $page);

        return ['items' => $offers['data'], 'meta' => $offers['meta']];
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    private function cuisineItems(): array
    {
        return CuisineType::query()->whereHas('restaurants', fn (Builder $q): Builder => $q->where('restaurants.is_active', true))
            ->orderBy('name')->orderBy('id')->get(['cuisine_types.id', 'name', 'slug'])
            ->map(fn (CuisineType $cuisine): array => ['id' => (int) $cuisine->id, 'name' => $cuisine->name, 'slug' => $cuisine->slug])->all();
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
        $products = $this->productQuery($request)->orderByDesc('products.is_featured')->orderBy('products.name')->orderBy('products.id')
            ->paginate(perPage: $perPage, page: $page);
        $this->attachProductFavorites($products->getCollection(), $request);

        return ['items' => $products->getCollection()->map(fn (Product $product): array => $this->productData($product, $request))->all(),
            'meta' => $this->paginationMeta($products)];
    }

    private function restaurantQuery(Request $request): Builder
    {
        $query = Restaurant::query()->select('restaurants.*')->where('restaurants.is_active', true)
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
            ->whereHas('restaurant', fn (Builder $q): Builder => $q->where('restaurants.is_active', true))
            ->with(['media', 'restaurant.media', 'category', 'offers']);
    }

    private function applyRestaurantFilters(Builder $query, array $filters, Request $request): Builder
    {
        if (isset($filters['cuisineId'])) {
            $query->whereHas('cuisineTypes', fn (Builder $cuisine): Builder => $cuisine->where('cuisine_types.id', $filters['cuisineId']));
        }
        if (isset($filters['categoryId'])) {
            $query->whereHas('products', fn (Builder $product): Builder => $this->availableProducts($product)->where('products.category_id', $filters['categoryId']));
        }
        if (($filters['openNow'] ?? false) === true) {
            RestaurantV2Availability::constrainOpen($query);
        }
        if (($filters['hasOffers'] ?? false) === true) {
            $query->whereHas('offers', fn (Builder $offer): Builder => $this->eligibleOffers($offer));
        }
        $this->sortRestaurants($query, (string) ($filters['sort'] ?? 'rating'), $request);

        return $query;
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

    private function offerData(Offer $offer): array
    {
        $restaurant = $offer->getRelationValue('restaurant');

        return [
            'id' => (int) $offer->id, 'restaurantId' => (int) $offer->restaurant_id,
            'restaurantName' => $restaurant?->name,
            'restaurantImageUrl' => $restaurant !== null && ($image = $restaurant->getFirstMediaUrl('primary-image')) !== '' ? $image : null,
            'title' => $offer->name, 'description' => $offer->description,
            'discountType' => $offer->discount_type?->value ?? $offer->discount_type,
            'discountValue' => $offer->discount_value !== null ? number_format((float) $offer->discount_value, 2, '.', '') : null,
            'startsAt' => $offer->starts_at?->toIso8601String(), 'endsAt' => $offer->ends_at?->toIso8601String(),
        ];
    }

    private function eligibleOffers(Builder|Relation $query): Builder|Relation
    {
        $now = CarbonImmutable::now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    private function availableProducts(Builder|Relation $query): Builder|Relation
    {
        return $query->where(function ($availability): void {
            $availability->where('is_available', true)
                ->orWhere(fn ($expired) => $expired->where('is_available', false)
                    ->whereNotNull('unavailable_until')->where('unavailable_until', '<', now()));
        });
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
