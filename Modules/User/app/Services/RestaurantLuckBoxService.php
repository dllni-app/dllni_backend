<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\FoodDiscoveryCategory;
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
            'foodCategories' => FoodDiscoveryCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($family) => ['id' => (int) $family->id, 'name' => $family->name_ar, 'slug' => $family->slug])->all(),
            'modes' => ['any', 'sandwiches', 'meals', 'family'],
            'priceBasis' => 'food_subtotal',
            'dietaryRestrictionsVerified' => false,
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
        ?int $foodCategoryId = null,
        array $itemTypes = [],
        string $mode = 'any',
    ): array {
        $budgetTotal = round($groupSize * $budgetPerPerson, 2);
        $driver = Restaurant::query()->getConnection()->getDriverName();
        $modeTypes = match ($mode) {
            'sandwiches' => ['sandwich', 'burger'],
            'meals' => ['meal', 'combo', 'dish', 'pizza', 'breakfast'],
            'family' => ['family_meal'],
            default => [],
        };
        $incompatibleTypes = $modeTypes !== [] && $itemTypes !== [] && array_intersect($modeTypes, $itemTypes) === [];
        $types = $modeTypes === [] ? $itemTypes : ($itemTypes === [] ? $modeTypes : array_values(array_intersect($modeTypes, $itemTypes)));
        // Absence from the product name is not evidence of dietary suitability.
        // The catalog has no verified dietary certification for these legacy flags.
        if ($restrictions !== [] || $incompatibleTypes) {
            return ['budget' => ['groupSize' => $groupSize, 'budgetPerPerson' => $budgetPerPerson, 'total' => $budgetTotal], 'bundles' => [], 'priceBasis' => 'food_subtotal', 'noMatchReason' => $restrictions !== [] ? 'unverified_restrictions' : 'incompatible_item_types'];
        }

        $restaurantsQuery = Restaurant::query()
            ->where('is_active', true)
            ->where('is_temporarily_closed', false)
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

            $products = $this->productsForRestaurant($restaurant->id, $foodCategoryId, $types);
            if ($products->isEmpty()) {
                continue;
            }

            $bv = $this->buildCoveredBundle($restaurant, $products, $budgetTotal, $groupSize, 'best_value');
            if ($bv !== null && ($bestValue === null || $bv['score'] > $bestValue['score'])) {
                $bestValue = $bv;
            }

            $ft = $this->buildCoveredBundle($restaurant, $products, $budgetTotal, $groupSize, 'fastest');
            if ($ft !== null && ($fastest === null || $ft['score'] > $fastest['score'])) {
                $fastest = $ft;
            }

            $bl = $this->buildCoveredBundle($restaurant, $products, $budgetTotal, $groupSize, 'balanced');
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
            'priceBasis' => 'food_subtotal',
            'noMatchReason' => $bundles === [] ? 'no_verified_coverage_within_budget' : null,
        ];
    }

    /** @return Collection<int, Product> */
    private function productsForRestaurant(int $restaurantId, ?int $familyId, array $types): Collection
    {
        return RestaurantFoodEligibility::curatedMeals(Product::query())
            ->where('restaurant_id', $restaurantId)
            ->where('stock_quantity', '>', 0)
            ->where('servings', '>', 0)
            ->when($familyId !== null, fn (Builder $query) => $query->where('primary_food_category_id', $familyId))
            ->when($types !== [], fn (Builder $query) => $query->whereIn('item_type', $types))
            ->with(['foodCategory', 'media', 'offers', 'modifierGroups.modifiers'])
            ->orderBy('id')->get();
    }

    /**
     * Minimum required modifier cost is included in the estimated food subtotal.
     * The customer must still configure required choices before adding a line.
     * Unknown portions never contribute to group coverage.
     */
    private function candidate(Product $product): ?array
    {
        $requiredCost = 0;
        $configuration = false;
        foreach ($product->modifierGroups as $group) {
            $minimum = max((int) $group->min_selections, $group->is_required ? 1 : 0);
            if ($minimum === 0) continue;
            $configuration = true;
            if ($group->modifiers->count() < $minimum || (int) $group->max_selections < $minimum) return null;
            $requiredCost += $group->modifiers->sortBy('price')->take($minimum)->sum(fn ($modifier) => max(0, (int) round((float) $modifier->price * 100)));
        }
        return [
            'product' => $product,
            'price' => (int) round($product->effectivePrice() * 100) + $requiredCost,
            'basePrice' => (int) round($product->effectivePrice() * 100),
            'requiredCost' => $requiredCost,
            'requiresConfiguration' => $configuration,
            'servings' => (int) $product->servings,
            'stock' => (int) $product->stock_quantity,
            'family' => (int) $product->primary_food_category_id,
            'minutes' => max(1, (int) ($product->preparation_time ?? $product->restaurant?->estimated_preparation_time ?? 20)),
        ];
    }

    /**
     * Bounded integer-cent dynamic programming: retain a cheapest feasible state
     * for each covered portion count; stock and quantity are never exceeded.
     */
    private function cheapest(array $candidates, int $need, int $budget): ?array
    {
        if ($need <= 0) return ['cost' => 0, 'lines' => [], 'coverage' => 0, 'families' => []];
        $states = [0 => ['cost' => 0, 'lines' => [], 'coverage' => 0, 'families' => []]];
        foreach ($candidates as $candidate) {
            $next = $states;
            foreach ($states as $covered => $state) {
                if ($covered >= $need) continue;
                $limit = min($candidate['stock'], (int) ceil(($need - $covered) / $candidate['servings']));
                for ($quantity = 1; $quantity <= $limit; $quantity++) {
                    $cost = $state['cost'] + $candidate['price'] * $quantity;
                    if ($cost > $budget) break;
                    $coverage = $state['coverage'] + $candidate['servings'] * $quantity;
                    $key = min($need, $coverage);
                    $families = $state['families'] + [$candidate['family'] => true];
                    if (!isset($next[$key]) || $cost < $next[$key]['cost']
                        || ($cost === $next[$key]['cost'] && count($families) > count($next[$key]['families']))) {
                        $next[$key] = ['cost' => $cost, 'coverage' => $coverage, 'families' => $families,
                            'lines' => [...$state['lines'], [$candidate, $quantity]]];
                    }
                }
            }
            $states = $next;
        }
        return $states[$need] ?? null;
    }

    private function buildCoveredBundle(Restaurant $restaurant, Collection $products, float $budgetTotal, int $groupSize, string $strategy): ?array
    {
        $budget = (int) round($budgetTotal * 100);
        $candidates = $products->map(fn (Product $product) => $this->candidate($product))->filter()
            ->filter(fn ($candidate) => $candidate['price'] >= 0 && $candidate['servings'] > 0)->values()->all();
        $solution = $this->cheapest($candidates, $groupSize, $budget);
        if ($solution === null) return null;

        if ($strategy === 'fastest') {
            $times = array_values(array_unique(array_column($candidates, 'minutes')));
            sort($times);
            // Feasibility is monotone in the allowed preparation time.
            $lo = 0; $hi = count($times) - 1;
            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                $alternative = $this->cheapest(array_values(array_filter($candidates, fn ($c) => $c['minutes'] <= $times[$mid])), $groupSize, $budget);
                if ($alternative !== null) {
                    $solution = $alternative;
                    $hi = $mid - 1;
                } else {
                    $lo = $mid + 1;
                }
            }
        } elseif ($strategy === 'balanced') {
            // Try a diverse seed prefix, then fill remaining known portions with
            // the same exact bounded-cost solver. Keep only feasible results.
            $byFamily = collect($candidates)->groupBy('family')
                ->map(fn ($group) => $group->sortBy(fn ($c) => $c['price'] / $c['servings'])->first())
                ->sortBy(fn ($c) => $c['price'])->values()->all();
            foreach (array_slice($byFamily, 0, 12) as $first) {
                $prefix = ['cost' => $first['price'], 'coverage' => $first['servings'], 'families' => [$first['family'] => true], 'lines' => [[$first, 1]]];
                if ($prefix['cost'] > $budget) continue;
                $remaining = array_map(function ($candidate) use ($first) {
                    if ($candidate['product']->id === $first['product']->id) $candidate['stock']--;
                    return $candidate;
                }, $candidates);
                $tail = $this->cheapest($remaining, $groupSize - $prefix['coverage'], $budget - $prefix['cost']);
                if ($tail === null) continue;
                $alternative = ['cost' => $prefix['cost'] + $tail['cost'], 'coverage' => $prefix['coverage'] + $tail['coverage'], 'families' => $prefix['families'] + $tail['families'], 'lines' => [...$prefix['lines'], ...$tail['lines']]];
                if (count($alternative['families']) > count($solution['families'])
                    || (count($alternative['families']) === count($solution['families']) && $alternative['cost'] < $solution['cost'])) $solution = $alternative;
            }
        }

        $lines = [];
        $minutes = 0;
        foreach ($solution['lines'] as [$candidate, $quantity]) {
            $product = $candidate['product'];
            $minutes = max($minutes, $candidate['minutes']);
            if (isset($lines[$product->id])) {
                $lines[$product->id]['quantity'] += $quantity;
                $lines[$product->id]['lineTotal'] = round($lines[$product->id]['quantity'] * $lines[$product->id]['unitPrice'], 2);
                continue;
            }
            $lines[$product->id] = [
                'productId' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'unitPrice' => $candidate['price'] / 100,
                'baseUnitPrice' => $candidate['basePrice'] / 100,
                'minimumConfigurationPrice' => $candidate['requiredCost'] / 100,
                'lineTotal' => $candidate['price'] * $quantity / 100,
                'imageUrl' => $product->getFirstMediaUrl('primary-image') ?: null,
                'itemType' => $product->item_type,
                'foodCategoryId' => $product->primary_food_category_id,
                'servings' => $candidate['servings'],
                'includedComponents' => $product->included_components,
                'requiresConfiguration' => $candidate['requiresConfiguration'],
            ];
        }
        $lines = array_values($lines);
        $minutes = max($minutes, (int) ($restaurant->estimated_preparation_time ?? 10)) + 2 * count($lines);
        return [
            'restaurant' => $restaurant,
            'lineItems' => $lines,
            'totalPrice' => $solution['cost'] / 100,
            'totalProducts' => array_sum(array_column($lines, 'quantity')),
            'itemsDescription' => implode('، ', array_map(fn ($line) => $line['quantity'].'× '.$line['name'], $lines)),
            'estimatedMinutes' => $minutes,
            'coverage' => $solution['coverage'],
            'requiresConfiguration' => in_array(true, array_column($lines, 'requiresConfiguration'), true),
            'score' => match ($strategy) {
                'fastest' => -$minutes,
                'balanced' => count($solution['families']) * 100000000 - $solution['cost'] / max(1, $budget),
                default => -$solution['cost'],
            },
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
            'coverage' => $bundle['coverage'],
            'requiresConfiguration' => $bundle['requiresConfiguration'],
            'priceBasis' => 'food_subtotal',
        ];
    }
}
