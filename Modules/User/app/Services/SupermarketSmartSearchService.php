<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Collection;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Services\SmSemanticProductSearchService;

final class SupermarketSmartSearchService
{
    public function __construct(
        private readonly SmSemanticProductSearchService $productSearch,
        private readonly SmartSearchStoreResolver $storeResolver,
        private readonly SmartSearchRecipeResolver $recipeResolver,
        private readonly SmartSearchContextResolver $contextResolver,
        private readonly SmartSearchUnitNormalizer $units,
    ) {}

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    public function search(array $intent, int $topK = 20): array
    {
        $recipe = null;
        $recipeSource = null;
        $items = is_array($intent['items'] ?? null) ? $intent['items'] : [];
        $goal = (string) ($intent['goal'] ?? 'direct_product_search');

        if ($goal === 'find_store') {
            return $this->searchStore($intent);
        }

        if ($goal === 'prepare_recipe') {
            $resolved = $this->recipeResolver->resolve($intent);
            $recipe = $resolved['recipe'];
            $recipeSource = $resolved['source'];
            $items = $resolved['items'];
        } elseif ($goal === 'prepare_context' && $items === []) {
            $resolvedContext = $this->contextResolver->resolve(
                is_string($intent['contextName'] ?? null) ? $intent['contextName'] : null
            );
            $items = $resolvedContext['items']
                ?? (is_array($intent['inferredIngredients'] ?? null)
                    ? $intent['inferredIngredients']
                    : []);
        }

        if ($items === [] && is_string($intent['searchText'] ?? null) && mb_trim($intent['searchText']) !== '') {
            $items = [[
                'query' => mb_trim($intent['searchText']),
                'quantity' => null,
                'unit' => null,
                'required' => true,
                'priority' => 100,
            ]];
        }

        $items = $this->normalizeItems($items);
        $preferredStore = $this->storeResolver->resolve(
            is_string($intent['preferredStoreName'] ?? null) ? $intent['preferredStoreName'] : null
        );

        foreach ($items as $index => $_item) {
            $items[$index]['key'] = 'item_'.$index;
        }

        $strictStore = (bool) ($intent['storeStrict'] ?? false);
        $limit = max(10, min(30, $topK));
        $prefetched = $this->prefetchSemanticCandidates($items, $preferredStore, $strictStore, $limit);

        $perItemCandidates = [];
        foreach ($items as $item) {
            $key = (string) $item['key'];
            $perItemCandidates[$key] = $this->findCandidates(
                $item,
                $preferredStore,
                $strictStore,
                $limit,
                $prefetched[$key] ?? null,
            );
        }

        $storeGroups = $this->buildStoreGroups($items, $perItemCandidates, $preferredStore);
        $allStoreGroups = collect($storeGroups);

        $preferredGroup = null;
        if ($preferredStore !== null) {
            $preferredGroup = $allStoreGroups->first(
                fn (array $group): bool => (int) ($group['store']['id'] ?? 0) === (int) $preferredStore->id
            );

            if ($preferredGroup === null) {
                $preferredGroup = $this->emptyStoreGroup($preferredStore, count($items));
            }
        }

        $alternatives = $allStoreGroups
            ->reject(fn (array $group): bool => $preferredStore !== null
                && (int) ($group['store']['id'] ?? 0) === (int) $preferredStore->id);

        if (($intent['sameStoreRequired'] ?? false) === true) {
            $fullCoverage = $alternatives->filter(fn (array $group): bool => ($group['coverage'] ?? 0) >= 1.0);
            if ($fullCoverage->isNotEmpty()) {
                $alternatives = $fullCoverage;
            }
        }

        $alternatives = $alternatives
            ->sort(function (array $a, array $b): int {
                $coverageCompare = ($b['coverage'] ?? 0) <=> ($a['coverage'] ?? 0);
                if ($coverageCompare !== 0) {
                    return $coverageCompare;
                }

                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            })
            ->take($topK)
            ->values()
            ->all();

        $unresolved = collect($items)
            ->filter(function (array $item) use ($perItemCandidates): bool {
                $key = (string) $item['key'];

                return ($perItemCandidates[$key] ?? []) === [];
            })
            ->values()
            ->all();

        return [
            'mode' => 'basket',
            'recipe' => $recipe,
            'recipeSource' => $recipeSource,
            'items' => $items,
            'preferredStore' => $preferredGroup,
            'alternativeStores' => $alternatives,
            'unresolvedItems' => $unresolved,
            'sameStoreRequired' => (bool) ($intent['sameStoreRequired'] ?? false),
            'storeStrict' => (bool) ($intent['storeStrict'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function searchStore(array $intent): array
    {
        $query = is_string($intent['preferredStoreName'] ?? null)
            && mb_trim((string) $intent['preferredStoreName']) !== ''
            ? (string) $intent['preferredStoreName']
            : (string) ($intent['searchText'] ?? '');

        $stores = $this->storeResolver->search($query, 12);
        if ($stores === []) {
            $store = $this->storeResolver->resolve($query);
            $stores = $store === null ? [] : [$store];
        }

        return [
            'mode' => 'store',
            'stores' => array_map(fn (SmStore $store): array => $this->storeData($store), $stores),
            'preferredStore' => null,
            'alternativeStores' => [],
            'items' => [],
            'unresolvedItems' => [],
            'sameStoreRequired' => false,
            'storeStrict' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function findCandidates(
        array $item,
        ?SmStore $preferredStore,
        bool $strictStore,
        int $limit,
        ?array $prefetchedRows = null,
    ): array {
        $rows = $prefetchedRows;

        if ($rows === null) {
            $query = (string) $item['query'];
            $rows = [];

            if ($preferredStore !== null) {
                $rows = array_merge($rows, $this->productSearch->search([
                    'query' => $query,
                    'top_k' => $limit,
                    'store_id' => (string) $preferredStore->id,
                    'is_available' => true,
                ]) ?? []);
            }

            if (! $strictStore) {
                $rows = array_merge($rows, $this->productSearch->search([
                    'query' => $query,
                    'top_k' => max($limit * 2, 20),
                    'is_available' => true,
                ]) ?? []);
            }
        }

        $rows = collect($rows)
            ->unique('id')
            ->values();

        if ($rows->isEmpty()) {
            return $this->localCandidates($item, $preferredStore, $strictStore, $limit);
        }

        $scoreById = $rows->pluck('score', 'id');
        $products = SmProduct::query()
            ->whereIn('id', $scoreById->keys()->all())
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0)
            ->whereHas('store', fn ($q) => $q
                ->where('is_active', true)
                ->where(fn ($sq) => $sq->whereNull('suspension_until')->orWhere('suspension_until', '<=', now())))
            ->with(['store', 'category', 'masterProduct.media', 'media', 'offerProducts.offer'])
            ->get();

        return $this->mapCandidates($products, $scoreById, $item, $preferredStore);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string, list<array{id:int, score:float|null}>>
     */
    private function prefetchSemanticCandidates(
        array $items,
        ?SmStore $preferredStore,
        bool $strictStore,
        int $limit,
    ): array {
        $queries = [];
        foreach ($items as $item) {
            $key = (string) $item['key'];
            if ($preferredStore !== null) {
                $queries[] = [
                    'key' => 'preferred:'.$key,
                    'query' => (string) $item['query'],
                    'top_k' => $limit,
                    'store_id' => (string) $preferredStore->id,
                    'is_available' => true,
                ];
            }
            if (! $strictStore) {
                $queries[] = [
                    'key' => 'global:'.$key,
                    'query' => (string) $item['query'],
                    'top_k' => max($limit * 2, 20),
                    'is_available' => true,
                ];
            }
        }

        $batch = $this->productSearch->batchSearch($queries);
        if ($batch === null) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            $key = (string) $item['key'];
            $rows = [];
            if ($preferredStore !== null) {
                $rows = array_merge($rows, $batch['preferred:'.$key] ?? []);
            }
            if (! $strictStore) {
                $rows = array_merge($rows, $batch['global:'.$key] ?? []);
            }
            $result[$key] = collect($rows)->unique('id')->values()->all();
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return list<array<string,mixed>>
     */
    private function localCandidates(array $item, ?SmStore $preferredStore, bool $strictStore, int $limit): array
    {
        $search = addcslashes((string) $item['query'], '%_\\');
        $query = SmProduct::query()
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0)
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"))
            ->whereHas('store', fn ($q) => $q->where('is_active', true))
            ->with(['store', 'category', 'masterProduct.media', 'media', 'offerProducts.offer'])
            ->limit(max(20, $limit));

        if ($strictStore && $preferredStore !== null) {
            $query->where('store_id', $preferredStore->id);
        }

        $products = $query->get();
        $scores = $products->mapWithKeys(fn (SmProduct $product): array => [$product->id => 0.55]);

        return $this->mapCandidates($products, $scores, $item, $preferredStore);
    }

    /**
     * @param  Collection<int, SmProduct>  $products
     * @param  Collection<int|string, mixed>  $scores
     * @param  array<string,mixed>  $item
     * @return list<array<string,mixed>>
     */
    private function mapCandidates(Collection $products, Collection $scores, array $item, ?SmStore $preferredStore): array
    {
        return $products
            ->map(function (SmProduct $product) use ($scores, $item, $preferredStore): ?array {
                $semantic = (float) ($scores[$product->id] ?? 0.55);
                $lexical = $this->lexicalRelevance($product, (string) $item['query']);

                if (! $this->passesRelevanceGate($semantic, $lexical)) {
                    return null;
                }

                $master = $product->masterProduct;
                [$parsedPackageQuantity, $parsedPackageUnit] = $this->extractPackageFromProductText(
                    mb_trim($product->name.' '.($product->description ?? ''))
                );
                $packageQuantity = $parsedPackageQuantity
                    ?? ($master?->package_quantity !== null ? (float) $master->package_quantity : null);
                $packageUnit = $parsedPackageUnit
                    ?? $master?->package_unit
                    ?? ($master?->unit?->value ?? (is_string($master?->unit) ? $master->unit : null));

                $packagePlan = $this->units->packagesRequired(
                    is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : null,
                    is_string($item['unit'] ?? null) ? $item['unit'] : null,
                    $packageQuantity,
                    $packageUnit,
                );

                $quantityScore = $packagePlan === null
                    ? 0.65
                    : (($packagePlan['exact'] ?? false) ? 1.0 : 1.0 / (1.0 + (float) ($packagePlan['overage'] ?? 0)));

                $score = $semantic * 0.68 + $lexical * 0.18 + $quantityScore * 0.14;
                if ($preferredStore !== null && (int) $product->store_id === (int) $preferredStore->id) {
                    $score += 0.06;
                }

                $resource = (new SmProductResource($product))->resolve(request());
                $resource['matchScore'] = round(min(1.0, $score), 4);
                $resource['lexicalMatchScore'] = round($lexical, 4);
                $resource['packagePlan'] = $packagePlan;

                return [
                    'product' => $resource,
                    'storeId' => (int) $product->store_id,
                    'score' => min(1.0, $score),
                    'packagePlan' => $packagePlan,
                ];
            })
            ->filter(fn (?array $row): bool => $row !== null)
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    private function passesRelevanceGate(float $semantic, float $lexical): bool
    {
        if ($lexical >= 0.72) {
            return true;
        }

        return $semantic >= 0.88 && $lexical >= 0.45;
    }

    private function lexicalRelevance(SmProduct $product, string $query): float
    {
        $queryTokens = $this->searchTokens($query);
        if ($queryTokens === []) {
            return 0.0;
        }

        $productText = implode(' ', array_filter([
            $product->name,
            $product->description,
            $product->category?->name,
        ]));
        $localScore = $this->lexicalScoreForTokens(
            $queryTokens,
            $this->searchTokens($productText),
        );

        $masterName = is_string($product->masterProduct?->name)
            ? $product->masterProduct->name
            : '';

        if ($masterName === '' || $localScore < 0.30) {
            return $localScore;
        }

        $masterScore = $this->lexicalScoreForTokens(
            $queryTokens,
            $this->searchTokens($masterName),
        );

        return max(
            $localScore,
            min(0.85, $masterScore * 0.85),
        );
    }

    /**
     * @param  list<string>  $queryTokens
     * @param  list<string>  $candidateTokens
     */
    private function lexicalScoreForTokens(array $queryTokens, array $candidateTokens): float
    {
        if ($candidateTokens === []) {
            return 0.0;
        }

        $scores = [];
        foreach ($queryTokens as $queryToken) {
            $best = 0.0;
            foreach ($candidateTokens as $candidateToken) {
                $best = max($best, $this->tokenSimilarity($queryToken, $candidateToken));
            }
            $scores[] = $best;
        }

        if ($scores === []) {
            return 0.0;
        }

        $average = array_sum($scores) / count($scores);

        if (min($scores) < 0.35) {
            $average *= 0.70;
        }

        return min(1.0, $average);
    }

    /** @return list<string> */
    private function searchTokens(string $text): array
    {
        $normalized = mb_strtolower($text);
        $normalized = strtr($normalized, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ک' => 'ك',
            'ی' => 'ي',
        ]);
        $normalized = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        return collect(preg_split('/\s+/u', mb_trim($normalized)) ?: [])
            ->filter(fn (string $token): bool => $token !== '' && mb_strlen($token) >= 2)
            ->values()
            ->all();
    }

    private function tokenSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $minLength = min(mb_strlen($a), mb_strlen($b));
        if ($minLength >= 2 && (str_contains($a, $b) || str_contains($b, $a))) {
            return 0.92;
        }

        $distance = $this->unicodeLevenshtein($a, $b);
        $maxLength = max(mb_strlen($a), mb_strlen($b));

        if ($maxLength === 0) {
            return 0.0;
        }

        $similarity = 1.0 - ($distance / $maxLength);

        return $similarity >= 0.60 ? $similarity : 0.0;
    }

    private function unicodeLevenshtein(string $a, string $b): int
    {
        $left = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $right = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($left === []) {
            return count($right);
        }
        if ($right === []) {
            return count($left);
        }

        $previous = range(0, count($right));

        foreach ($left as $i => $leftChar) {
            $current = [$i + 1];
            foreach ($right as $j => $rightChar) {
                $cost = $leftChar === $rightChar ? 0 : 1;
                $current[$j + 1] = min(
                    $current[$j] + 1,
                    $previous[$j + 1] + 1,
                    $previous[$j] + $cost,
                );
            }
            $previous = $current;
        }

        return $previous[count($right)];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  array<string,list<array<string,mixed>>>  $perItemCandidates
     * @return list<array<string,mixed>>
     */
    private function buildStoreGroups(array $items, array $perItemCandidates, ?SmStore $preferredStore): array
    {
        $storeIds = [];
        foreach ($perItemCandidates as $candidates) {
            foreach ($candidates as $candidate) {
                $storeIds[(int) $candidate['storeId']] = true;
            }
        }

        if ($preferredStore !== null) {
            $storeIds[(int) $preferredStore->id] = true;
        }

        $stores = SmStore::query()->whereIn('id', array_keys($storeIds))->get()->keyBy('id');
        $groups = [];

        foreach (array_keys($storeIds) as $storeId) {
            $matches = [];
            $matched = 0;
            $scoreTotal = 0.0;

            foreach ($items as $item) {
                $key = (string) $item['key'];
                $candidate = collect($perItemCandidates[$key] ?? [])
                    ->first(fn (array $row): bool => (int) $row['storeId'] === (int) $storeId);

                if ($candidate !== null) {
                    $matched++;
                    $scoreTotal += (float) $candidate['score'];
                    $matches[] = [
                        'item' => $item,
                        'status' => 'matched',
                        'product' => $candidate['product'],
                    ];
                } else {
                    $matches[] = [
                        'item' => $item,
                        'status' => 'missing',
                        'product' => null,
                    ];
                }
            }

            $store = $stores->get($storeId);
            if (! $store instanceof SmStore) {
                continue;
            }

            $count = max(1, count($items));
            $coverage = $matched / $count;
            $averageMatch = $matched > 0 ? $scoreTotal / $matched : 0.0;
            $quality = min(1.0, max(0.0, (float) ($store->average_rating ?? 0) / 5));
            $score = $coverage * 0.70 + $averageMatch * 0.25 + $quality * 0.05;

            $groups[] = [
                'store' => $this->storeData($store),
                'coverage' => round($coverage, 4),
                'matchedItems' => $matched,
                'totalItems' => count($items),
                'score' => round($score, 4),
                'items' => $matches,
            ];
        }

        return $groups;
    }

    /** @param list<array<string,mixed>> $items */
    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->filter(fn ($item): bool => is_array($item) && is_string($item['query'] ?? null) && mb_trim($item['query']) !== '')
            ->map(function (array $item): array {
                return [
                    'query' => mb_trim((string) $item['query']),
                    'ingredientKey' => is_string($item['ingredientKey'] ?? null) ? $item['ingredientKey'] : null,
                    'quantity' => is_numeric($item['quantity'] ?? null) && (float) $item['quantity'] > 0 ? (float) $item['quantity'] : null,
                    'unit' => $this->units->normalize(is_string($item['unit'] ?? null) ? $item['unit'] : null),
                    'required' => (bool) ($item['required'] ?? true),
                    'priority' => is_numeric($item['priority'] ?? null) ? (int) $item['priority'] : 100,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{0:float|null,1:string|null} */
    private function extractPackageFromProductText(string $text): array
    {
        if (preg_match(
            '/(\d+(?:[.,]\d+)?)\s*(كيلو\s*غرام|كيلوغرام|كيلو|كغ|kg|غرام|gram|g|ليتر|لتر|liter|l|ميلي\s*ليتر|مل|ml)\b/ui',
            $text,
            $matches,
        )) {
            $quantity = (float) str_replace(',', '.', $matches[1]);
            $unit = $this->units->normalize($matches[2]);

            return [$quantity, $unit];
        }

        return [null, null];
    }

    private function emptyStoreGroup(SmStore $store, int $totalItems): array
    {
        return [
            'store' => $this->storeData($store),
            'coverage' => 0.0,
            'matchedItems' => 0,
            'totalItems' => $totalItems,
            'score' => 0.0,
            'items' => [],
        ];
    }

    private function storeData(SmStore $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'description' => $store->description,
            'address' => $store->address,
            'averageRating' => (float) ($store->average_rating ?? 0),
            'trustScore' => (float) ($store->trust_score ?? 0),
        ];
    }
}
