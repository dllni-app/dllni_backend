<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Collection;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Services\RestaurantSemanticProductSearchService;
use Modules\Resturants\Services\RestaurantSemanticSearchService;
use Modules\User\Http\Resources\UserRestaurantProductWithOffersResource;

final class RestaurantSmartSearchService
{
    private const int DEFAULT_CANDIDATES = 60;

    public function __construct(
        private readonly RestaurantSemanticProductSearchService $mealSearch,
        private readonly RestaurantSemanticSearchService $restaurantSearch,
        private readonly RestaurantProductTypeResolver $productTypeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    public function search(array $intent, int $topK = 20): array
    {
        if (($intent['goal'] ?? 'find_food') === 'find_restaurant') {
            return $this->searchRestaurants($intent, $topK);
        }

        $query = is_string($intent['searchText'] ?? null) && mb_trim($intent['searchText']) !== ''
            ? mb_trim($intent['searchText'])
            : implode(' ', array_merge($intent['concepts'] ?? [], $intent['attributes'] ?? []));

        $payload = [
            'query' => $query,
            'top_k' => max(self::DEFAULT_CANDIDATES, $topK * 3),
            'is_available' => true,
        ];

        $semantic = $this->mealSearch->search($payload);
        $candidates = $this->hydrateCandidates($semantic, $query);

        $scored = $candidates
            ->map(fn (Product $product): ?array => $this->scoreProduct($product, $intent))
            ->filter()
            ->values();

        $exact = $scored
            ->filter(fn (array $row): bool => ($row['typeMatch'] ?? true) === true)
            ->sortByDesc('score')
            ->values();

        $alternatives = $scored
            ->reject(fn (array $row): bool => ($row['typeMatch'] ?? true) === true)
            ->sortByDesc('score')
            ->take(max(6, (int) ceil($topK / 2)))
            ->values();

        $groups = $this->groupByRestaurant($exact, $topK);

        return [
            'mode' => 'food',
            'restaurants' => $groups,
            'bestMatches' => $exact->take($topK)->pluck('product')->values()->all(),
            'similarAlternatives' => $alternatives->pluck('product')->values()->all(),
            'candidateCount' => $candidates->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function searchRestaurants(array $intent, int $topK): array
    {
        $query = (string) ($intent['searchText'] ?? '');
        $payload = [
            'query' => $query,
            'top_k' => max($topK * 2, 20),
            'is_active' => true,
        ];

        if (is_string($intent['cuisine'] ?? null) && $intent['cuisine'] !== '') {
            $payload['cuisine_type'] = $intent['cuisine'];
        }
        if (is_numeric($intent['minimumRating'] ?? null)) {
            $payload['average_rating_min'] = (float) $intent['minimumRating'];
        }

        $semantic = $this->restaurantSearch->search($payload);
        $scoreById = collect($semantic ?? [])->pluck('score', 'id');

        $queryBuilder = Restaurant::query()
            ->where('is_active', true)
            ->where('is_temporarily_closed', false)
            ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', now()))
            ->with('cuisineTypes');

        if ($scoreById->isNotEmpty()) {
            $queryBuilder->whereIn('id', $scoreById->keys()->all());
        } else {
            $escaped = addcslashes($query, '%_\\');
            $queryBuilder->where(fn ($q) => $q
                ->where('name', 'like', "%{$escaped}%")
                ->orWhere('description', 'like', "%{$escaped}%")
                ->orWhereHas('cuisineTypes', fn ($cq) => $cq->where('name', 'like', "%{$escaped}%")));
        }

        if (is_numeric($intent['maxPreparationMinutes'] ?? null)) {
            $queryBuilder->whereRaw(
                'COALESCE(estimated_preparation_time_max, estimated_preparation_time) <= ?',
                [(int) $intent['maxPreparationMinutes']]
            );
        }

        $restaurants = $queryBuilder->get()
            ->map(function (Restaurant $restaurant) use ($scoreById, $intent): array {
                $semanticScore = (float) ($scoreById[$restaurant->id] ?? 0.55);
                $prep = $this->restaurantPreparation($restaurant);
                $prepScore = $prep !== null ? max(0.0, 1.0 - min($prep, 60) / 60) : 0.5;
                $rating = min(1.0, max(0.0, ((float) $restaurant->average_rating) / 5));
                $score = $semanticScore * 0.75;

                if (($intent['fastPreparation'] ?? false) === true) {
                    $score += $prepScore * 0.15;
                } else {
                    $score += $prepScore * 0.05;
                }

                $score += $rating * (($intent['highRating'] ?? false) === true ? 0.15 : 0.05);

                return [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'description' => $restaurant->description,
                    'averageRating' => (float) $restaurant->average_rating,
                    'preparationTimeMin' => $restaurant->estimated_preparation_time_min,
                    'preparationTimeMax' => $restaurant->estimated_preparation_time_max ?? $restaurant->estimated_preparation_time,
                    'cuisines' => $restaurant->cuisineTypes->pluck('name')->values()->all(),
                    'matchScore' => round(min(1.0, $score), 4),
                ];
            })
            ->sortByDesc('matchScore')
            ->take($topK)
            ->values()
            ->all();

        return [
            'mode' => 'restaurant',
            'restaurants' => $restaurants,
            'bestMatches' => [],
            'similarAlternatives' => [],
            'candidateCount' => count($restaurants),
        ];
    }

    /**
     * @param  list<array{id:int, score:float|null}>|null  $semantic
     * @return Collection<int, Product>
     */
    private function hydrateCandidates(?array $semantic, string $query): Collection
    {
        $scoreById = collect($semantic ?? [])->pluck('score', 'id');

        $builder = Product::query()
            ->where('is_available', true)
            ->whereHas('restaurant', fn ($q) => $q
                ->where('is_active', true)
                ->where('is_temporarily_closed', false)
                ->where(fn ($nested) => $nested->whereNull('suspension_until')->orWhere('suspension_until', '<=', now())))
            ->with([
                'restaurant.cuisineTypes',
                'category',
                'offers',
                'media',
            ]);

        if ($scoreById->isNotEmpty()) {
            $builder->whereIn('id', $scoreById->keys()->all());
        } else {
            $escaped = addcslashes($query, '%_\\');
            $builder->where(fn ($q) => $q
                ->where('name', 'like', "%{$escaped}%")
                ->orWhere('description', 'like', "%{$escaped}%")
                ->orWhereHas('category', fn ($cq) => $cq->where('name', 'like', "%{$escaped}%")))
                ->limit(self::DEFAULT_CANDIDATES);
        }

        return $builder->get()
            ->map(function (Product $product) use ($scoreById): Product {
                $product->setAttribute('semantic_score', (float) ($scoreById[$product->id] ?? 0.55));

                return $product;
            });
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>|null
     */
    private function scoreProduct(Product $product, array $intent): ?array
    {
        $type = $this->resolveProductType($product);
        $requestedType = is_string($intent['itemType'] ?? null) ? $intent['itemType'] : null;
        $excludedTypes = is_array($intent['excludedItemTypes'] ?? null) ? $intent['excludedItemTypes'] : [];

        if (in_array($type, $excludedTypes, true)) {
            return null;
        }

        $text = $this->normalize(implode(' ', array_filter([
            $product->name,
            $product->description,
            $product->category?->name,
            ...(is_array($product->search_tags) ? $product->search_tags : []),
        ])));

        foreach (($intent['excludedAttributes'] ?? []) as $excluded) {
            if (is_string($excluded) && $excluded !== '' && str_contains($text, $this->normalize($excluded))) {
                return null;
            }
        }

        $price = $product->effectivePrice();
        if (is_numeric($intent['maxPrice'] ?? null) && $price > (float) $intent['maxPrice']) {
            return null;
        }

        $prepMinutes = $product->preparation_time
            ?? $product->restaurant?->estimated_preparation_time_max
            ?? $product->restaurant?->estimated_preparation_time;

        if (is_numeric($intent['maxPreparationMinutes'] ?? null)
            && ($prepMinutes === null || $prepMinutes > (int) $intent['maxPreparationMinutes'])) {
            return null;
        }

        if (is_numeric($intent['minimumRating'] ?? null)
            && (float) ($product->restaurant?->average_rating ?? 0) < (float) $intent['minimumRating']) {
            return null;
        }

        if (is_string($intent['restaurantName'] ?? null) && $intent['restaurantName'] !== '') {
            $restaurantName = $this->normalize((string) ($product->restaurant?->name ?? ''));
            if (! str_contains($restaurantName, $this->normalize($intent['restaurantName']))) {
                return null;
            }
        }

        $semantic = (float) ($product->getAttributes()['semantic_score'] ?? 0.55);
        $typeMatch = $this->productTypeResolver->matches($requestedType, $type);
        $typeScore = $typeMatch ? 1.0 : ($type === 'other' ? 0.45 : 0.2);
        $attributeScore = $this->attributeScore($text, $intent['attributes'] ?? []);
        $prepScore = $prepMinutes !== null ? max(0.0, 1.0 - min((int) $prepMinutes, 60) / 60) : 0.4;
        $priceScore = 1.0 / (1.0 + max(0.0, $price) / 50000);
        $ratingScore = min(1.0, max(0.0, ((float) ($product->restaurant?->average_rating ?? 0)) / 5));

        $weights = [
            'semantic' => 0.52,
            'type' => $requestedType !== null ? 0.20 : 0.08,
            'attributes' => ($intent['attributes'] ?? []) !== [] ? 0.12 : 0.05,
            'prep' => ($intent['fastPreparation'] ?? false) === true ? 0.20 : 0.05,
            'price' => ($intent['lowPrice'] ?? false) === true ? 0.18 : 0.03,
            'rating' => ($intent['highRating'] ?? false) === true ? 0.15 : 0.04,
        ];
        $sum = array_sum($weights);
        $score = (
            $semantic * $weights['semantic']
            + $typeScore * $weights['type']
            + $attributeScore * $weights['attributes']
            + $prepScore * $weights['prep']
            + $priceScore * $weights['price']
            + $ratingScore * $weights['rating']
        ) / $sum;

        $productData = (new UserRestaurantProductWithOffersResource($product))->resolve(request());
        $productData['itemType'] = $type;
        $productData['preparationTime'] = $prepMinutes;
        $productData['matchScore'] = round($score, 4);
        $productData['matchReasons'] = $this->matchReasons($typeMatch, $attributeScore, $prepMinutes, $intent);

        return [
            'product' => $productData,
            'score' => $score,
            'typeMatch' => $typeMatch,
            'restaurantId' => $product->restaurant_id,
        ];
    }

    /** @param Collection<int, array<string,mixed>> $rows */
    private function groupByRestaurant(Collection $rows, int $topK): array
    {
        return $rows
            ->groupBy('restaurantId')
            ->map(function (Collection $matches): array {
                $first = $matches->first();
                $product = $first['product'];
                $restaurant = $product['restaurant'] ?? null;

                return [
                    'restaurant' => $restaurant,
                    'score' => round((float) $matches->max('score'), 4),
                    'matches' => $matches->take(3)->pluck('product')->values()->all(),
                ];
            })
            ->sortByDesc('score')
            ->take($topK)
            ->values()
            ->all();
    }

    private function resolveProductType(Product $product): string
    {
        return $this->productTypeResolver->resolve(
            $product->item_type,
            $product->category?->name,
            $product->name,
            $product->description,
        );
    }

    private function attributeScore(string $text, mixed $attributes): float
    {
        if (! is_array($attributes) || $attributes === []) {
            return 1.0;
        }

        $matches = 0;
        foreach ($attributes as $attribute) {
            if (is_string($attribute) && $attribute !== '' && str_contains($text, $this->normalize($attribute))) {
                $matches++;
            }
        }

        return $matches / count($attributes);
    }

    /** @return list<string> */
    private function matchReasons(bool $typeMatch, float $attributeScore, ?int $prepMinutes, array $intent): array
    {
        $reasons = [];
        if ($typeMatch && is_string($intent['itemType'] ?? null)) {
            $reasons[] = 'requested_item_type';
        }
        if ($attributeScore >= 0.5 && ($intent['attributes'] ?? []) !== []) {
            $reasons[] = 'food_attributes';
        }
        if (($intent['fastPreparation'] ?? false) === true && $prepMinutes !== null) {
            $reasons[] = 'fast_preparation';
        }
        if (($intent['lowPrice'] ?? false) === true) {
            $reasons[] = 'price_preference';
        }
        if (($intent['highRating'] ?? false) === true) {
            $reasons[] = 'rating_preference';
        }

        return $reasons;
    }

    private function restaurantPreparation(Restaurant $restaurant): ?int
    {
        return $restaurant->estimated_preparation_time_max
            ?? $restaurant->estimated_preparation_time
            ?? $restaurant->estimated_preparation_time_min;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(mb_trim($value));
        $value = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ـ'], ['ا', 'ا', 'ا', 'ي', 'ه', ''], $value);
        $value = preg_replace('/[ًٌٍَُِّْـ]/u', '', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_trim($value);
    }
}
