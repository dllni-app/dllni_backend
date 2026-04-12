<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

final class RestaurantLuckBoxService
{
    private const float DEFAULT_SEARCH_RADIUS_KM = 10.0;

    private const array RESTRICTIONS = [
        ['value' => 'vegetarian', 'labelAr' => 'نباتي'],
        ['value' => 'gluten_free', 'labelAr' => 'خالي من الغلوتين'],
        ['value' => 'nut_free', 'labelAr' => 'خالي من المكسرات'],
        ['value' => 'dairy_free', 'labelAr' => 'خالي من الألبان'],
        ['value' => 'halal_friendly', 'labelAr' => 'مناسب للحلال'],
    ];

    /**
     * @return array{restrictions: list<array{value: string, labelAr: string}>, cuisineTypes: list<array{id: int, name: string}>}
     */
    public function options(): array
    {
        $cuisineTypes = CuisineType::query()
            ->whereHas('restaurants', fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (CuisineType $type) => [
                'id' => $type->id,
                'name' => $type->name,
            ])
            ->values()
            ->all();

        return [
            'restrictions' => self::RESTRICTIONS,
            'cuisineTypes' => $cuisineTypes,
        ];
    }

    /**
     * @param  list<string>  $restrictions
     * @return array{budget: array{groupSize: int, budgetPerPerson: float, total: float}, bundles: list<array<string, mixed>>}
     */
    public function suggest(
        int $groupSize,
        float $budgetPerPerson,
        array $restrictions = [],
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $cuisineTypeId = null,
        ?int $restaurantId = null,
    ): array {
        $budgetTotal = round($groupSize * $budgetPerPerson, 2);
        $driver = Restaurant::query()->getConnection()->getDriverName();

        $restaurantsQuery = Restaurant::query()
            ->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', now());
            });

        if ($restaurantId !== null) {
            $restaurantsQuery->where('id', $restaurantId);
        }

        if ($cuisineTypeId !== null) {
            $restaurantsQuery->whereHas('cuisineTypes', fn (Builder $q) => $q->where('cuisine_types.id', $cuisineTypeId));
        }

        if (is_numeric($latitude) && is_numeric($longitude)) {
            $lat = (float) $latitude;
            $lng = (float) $longitude;

            if ($driver === 'sqlite') {
                $latDelta = self::DEFAULT_SEARCH_RADIUS_KM / 111.0;
                $cosLat = cos(deg2rad($lat));
                $lngDelta = self::DEFAULT_SEARCH_RADIUS_KM / (111.0 * max(abs($cosLat), 0.01));

                $restaurantsQuery
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
                    ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta]);
            } else {
                $restaurantsQuery
                    ->select('restaurants.*')
                    ->selectRaw(
                        '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distance_km',
                        [$lat, $lng, $lat]
                    )
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->havingRaw('distance_km <= ?', [self::DEFAULT_SEARCH_RADIUS_KM]);
            }
        }

        $restaurants = $restaurantsQuery
            ->with('media')
            ->orderByDesc('is_featured')
            ->orderByDesc('average_rating')
            ->limit(40)
            ->get();

        $bestValue = null;
        $fastest = null;
        $balanced = null;

        foreach ($restaurants as $restaurant) {
            if (! $restaurant instanceof Restaurant) {
                continue;
            }

            $products = $this->productsForRestaurant($restaurant->id, $restrictions);
            if ($products->isEmpty()) {
                continue;
            }

            $bv = $this->buildBestValueBundle($restaurant, $products, $budgetTotal);
            if ($bv !== null && ($bestValue === null || $bv['score'] > $bestValue['score'])) {
                $bestValue = $bv;
            }

            $ft = $this->buildFastestBundle($restaurant, $products, $budgetTotal);
            if ($ft !== null && ($fastest === null || $ft['score'] > $fastest['score'])) {
                $fastest = $ft;
            }

            $bl = $this->buildBalancedBundle($restaurant, $products, $budgetTotal);
            if ($bl !== null && ($balanced === null || $bl['score'] > $balanced['score'])) {
                $balanced = $bl;
            }
        }

        $bundles = array_values(array_filter([
            $bestValue ? $this->formatBundle('best_value', 'الأوفر', $bestValue) : null,
            $fastest ? $this->formatBundle('fastest', 'الأسرع', $fastest) : null,
            $balanced ? $this->formatBundle('balanced', 'المتوازن', $balanced) : null,
        ]));

        return [
            'budget' => [
                'groupSize' => $groupSize,
                'budgetPerPerson' => $budgetPerPerson,
                'total' => $budgetTotal,
            ],
            'bundles' => $bundles,
        ];
    }

    /**
     * @param  list<string>  $restrictions
     * @return Collection<int, Product>
     */
    private function productsForRestaurant(int $restaurantId, array $restrictions): Collection
    {
        $query = Product::query()
            ->where('restaurant_id', $restaurantId)
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0);

        $this->applyRestrictions($query, $restrictions);

        return $query->with(['category', 'media'])->orderBy('name')->get();
    }

    /**
     * @param  list<string>  $restrictions
     */
    private function applyRestrictions(Builder $query, array $restrictions): void
    {
        foreach ($restrictions as $restriction) {
            $patterns = match ($restriction) {
                'vegetarian' => ['meat', 'beef', 'chicken', 'fish', 'salmon', 'pork', 'lamb', 'turkey', 'لحم', 'دجاج', 'سمك'],
                'gluten_free' => ['bread', 'wheat', 'pasta', 'flour', 'gluten', 'barley', 'خبز', 'قمح'],
                'nut_free' => ['nut', 'almond', 'peanut', 'cashew', 'pistachio', 'جوز', 'لوز', 'فول سوداني'],
                'dairy_free' => ['milk', 'cheese', 'cream', 'yogurt', 'butter', 'dairy', 'حليب', 'جبن', 'زبدة'],
                'halal_friendly' => ['pork', 'bacon', 'ham', 'wine', 'beer', 'alcohol', 'خمر', 'لحم خنزير'],
                default => [],
            };

            foreach ($patterns as $word) {
                $like = '%'.$word.'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('name', 'not like', $like)
                        ->where(function (Builder $d) use ($like): void {
                            $d->whereNull('description')->orWhere('description', 'not like', $like);
                        });
                });
            }
        }
    }

    /**
     * @return array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}
     */
    private function lineItemFromProduct(Product $product, float $unitPrice): array
    {
        $url = $product->getFirstMediaUrl('primary-image');

        if ($url === '') {
            $url = $product->getFirstMediaUrl('images');
        }

        return [
            'productId' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'unitPrice' => $unitPrice,
            'lineTotal' => $unitPrice,
            'imageUrl' => $url !== '' ? $url : null,
        ];
    }

    private function effectivePrice(Product $product): float
    {
        $discounted = $product->discounted_price;

        return (float) (($discounted !== null && (float) $discounted > 0) ? $discounted : $product->price);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{restaurant: Restaurant, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}|null
     */
    private function buildBestValueBundle(Restaurant $restaurant, Collection $products, float $budgetTotal): ?array
    {
        $sorted = $products->sortBy(fn (Product $p) => $this->effectivePrice($p))->values();
        $lineItems = [];
        $total = 0.0;

        foreach ($sorted as $product) {
            $price = $this->effectivePrice($product);
            if ($price <= 0) {
                continue;
            }
            if ($total + $price > $budgetTotal + 0.0001) {
                continue;
            }
            $lineItems[] = $this->lineItemFromProduct($product, $price);
            $total += $price;
        }

        if ($lineItems === []) {
            return null;
        }

        $score = count($lineItems) / max($total, 0.01);

        return $this->bundlePayload($restaurant, $lineItems, $total, $score);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{restaurant: Restaurant, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}|null
     */
    private function buildFastestBundle(Restaurant $restaurant, Collection $products, float $budgetTotal): ?array
    {
        $targetMin = $budgetTotal * 0.45;
        $sorted = $products->sortByDesc(fn (Product $p) => $this->effectivePrice($p))->values();
        $lineItems = [];
        $total = 0.0;

        foreach ($sorted as $product) {
            $price = $this->effectivePrice($product);
            if ($price <= 0) {
                continue;
            }
            if ($total + $price > $budgetTotal + 0.0001) {
                continue;
            }
            $lineItems[] = $this->lineItemFromProduct($product, $price);
            $total += $price;
            if ($total >= $targetMin) {
                break;
            }
        }

        if ($lineItems === [] || $total < $targetMin * 0.25) {
            return null;
        }

        $itemCount = count($lineItems);
        $utilization = $total / max($budgetTotal, 0.01);
        $score = 500 - ($itemCount * 25) + ($utilization * 100);

        return $this->bundlePayload($restaurant, $lineItems, $total, $score);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{restaurant: Restaurant, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}|null
     */
    private function buildBalancedBundle(Restaurant $restaurant, Collection $products, float $budgetTotal): ?array
    {
        $byCategory = $products->groupBy(fn (Product $p) => $p->category_id ?? 0);
        $roundRobin = collect();
        $keys = $byCategory->keys()->sort()->values();
        $maxRounds = 50;

        for ($r = 0; $r < $maxRounds; $r++) {
            foreach ($keys as $key) {
                $group = $byCategory->get($key);
                if (! $group instanceof Collection) {
                    continue;
                }
                $picked = $group->sortBy(fn (Product $p) => $this->effectivePrice($p))->first();
                if ($picked instanceof Product) {
                    $roundRobin->push($picked);
                    $byCategory->put($key, $group->reject(fn (Product $p) => $p->id === $picked->id)->values());
                }
            }
        }

        $lineItems = [];
        $total = 0.0;
        foreach ($roundRobin->unique('id')->values() as $product) {
            $price = $this->effectivePrice($product);
            if ($price <= 0) {
                continue;
            }
            if ($total + $price > $budgetTotal + 0.0001) {
                break;
            }
            $lineItems[] = $this->lineItemFromProduct($product, $price);
            $total += $price;
        }

        if ($lineItems === []) {
            return null;
        }

        $distinctCategories = collect($lineItems)->map(function (array $li) use ($products): ?int {
            $product = $products->firstWhere('id', $li['productId']);

            return $product?->category_id;
        })->filter()->unique()->count();

        $score = (float) $distinctCategories * 10 + count($lineItems);

        return $this->bundlePayload($restaurant, $lineItems, $total, $score);
    }

    /**
     * @param  list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>  $lineItems
     * @return array{restaurant: Restaurant, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}
     */
    private function bundlePayload(Restaurant $restaurant, array $lineItems, float $total, float $score): array
    {
        $quantity = array_sum(array_column($lineItems, 'quantity'));
        $parts = [];

        foreach ($lineItems as $lineItem) {
            $parts[] = $lineItem['quantity'].'× '.$lineItem['name'];
        }

        $basePreparationMinutes = (int) ($restaurant->estimated_preparation_time ?? 12);
        $estimatedMinutes = min(120, max(10, $basePreparationMinutes + (2 * count($lineItems))));

        return [
            'restaurant' => $restaurant,
            'lineItems' => $lineItems,
            'totalPrice' => round($total, 2),
            'totalProducts' => $quantity,
            'itemsDescription' => implode('، ', $parts),
            'estimatedMinutes' => $estimatedMinutes,
            'score' => $score,
        ];
    }

    /**
     * @param  array{restaurant: Restaurant, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}  $bundle
     * @return array<string, mixed>
     */
    private function formatBundle(string $label, string $labelAr, array $bundle): array
    {
        $restaurant = $bundle['restaurant'];

        $primaryImageUrl = $restaurant->getFirstMediaUrl('primary-image');
        $bannerImageUrl = $restaurant->getFirstMediaUrl('banner-image');

        return [
            'label' => $label,
            'labelAr' => $labelAr,
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'primaryImageUrl' => $primaryImageUrl !== '' ? $primaryImageUrl : null,
                'bannerImageUrl' => $bannerImageUrl !== '' ? $bannerImageUrl : null,
            ],
            'totalProducts' => $bundle['totalProducts'],
            'itemsDescription' => $bundle['itemsDescription'],
            'totalPrice' => $bundle['totalPrice'],
            'estimatedMinutes' => $bundle['estimatedMinutes'],
            'lineItems' => $bundle['lineItems'],
        ];
    }
}
