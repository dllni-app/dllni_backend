<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Services\SmSemanticProductSearchService;
use Modules\User\Http\Requests\DiscoverSupermarketProductsRequest;
use Modules\User\Services\UserPopularSearchService;

final class SmProductsSearchController
{
    private const float SEMANTIC_STRONG_SCORE_THRESHOLD = 0.9;

    public function __construct(
        private readonly SmSemanticProductSearchService $semanticSearchService,
        private readonly UserPopularSearchService $popularSearches,
    ) {}

    public function __invoke(DiscoverSupermarketProductsRequest $request): AnonymousResourceCollection
    {
        $query = $this->resolveSemanticQuery($request);

        if ($query !== null && $request->integer('page', 1) === 1) {
            $this->popularSearches->record(
                UserPopularSearchService::SUPERMARKET,
                $query,
                UserPopularSearchService::PRODUCTS,
            );
        }

        if ($query !== null) {
            $semanticPaginator = $this->semanticSearch($request, $query);

            if ($semanticPaginator !== null) {
                return SmProductResource::collection($semanticPaginator);
            }
        }

        return $this->fallbackSearch($request, $query);
    }

    private function fallbackSearch(DiscoverSupermarketProductsRequest $request, ?string $resolvedQuery = null): AnonymousResourceCollection
    {
        $now = CarbonImmutable::now();

        $query = SmProduct::getQuery()
            ->where('is_available', true)
            ->whereHas('store', fn ($storeQuery) => $storeQuery
                ->where('is_active', true)
                ->where(fn ($q) => $q
                    ->whereNull('suspension_until')
                    ->orWhere('suspension_until', '<=', $now)))
            ->with(['media', 'store']);

        $search = $request->validated('search');
        if ((! is_string($search) || $search === '') && is_string($resolvedQuery) && $resolvedQuery !== '') {
            $search = $resolvedQuery;
        }

        if (is_string($search) && $search !== '') {
            $query->search($search);
        }

        $products = $query->paginate($request->integer('perPage', 20));

        return SmProductResource::collection($products);
    }

    private function resolveSemanticQuery(DiscoverSupermarketProductsRequest $request): ?string
    {
        $validated = $request->validated();

        $semanticQuery = $validated['query'] ?? $validated['search'] ?? $validated['filter']['search'] ?? null;

        if (! is_string($semanticQuery)) {
            return null;
        }

        $trimmed = mb_trim($semanticQuery);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function semanticSearch(DiscoverSupermarketProductsRequest $request, string $query): ?LengthAwarePaginator
    {
        $perPage = $request->integer('perPage', 20);
        $page = max(1, $request->integer('page', 1));

        $payload = [
            'query' => $query,
            'top_k' => $request->integer('top_k', max($perPage * $page, $perPage)),
        ];

        $storeId = $request->input('store_id', $request->input('filter.storeId'));
        if (is_numeric($storeId)) {
            $payload['store_id'] = (string) $storeId;
        }

        $categoryId = $request->input('category_id', $request->input('filter.categoryId'));
        if (is_numeric($categoryId)) {
            $payload['category_id'] = (string) $categoryId;
        }

        $priceMin = $request->input('price_min');
        if (is_numeric($priceMin)) {
            $payload['price_min'] = (float) $priceMin;
        }

        $priceMax = $request->input('price_max');
        if (is_numeric($priceMax)) {
            $payload['price_max'] = (float) $priceMax;
        }

        $isAvailable = $request->input('is_available', $request->input('filter.isAvailable'));
        if ($isAvailable !== null) {
            $payload['is_available'] = filter_var($isAvailable, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $results = $this->semanticSearchService->search($payload);

        if ($results === null || $results === []) {
            return null;
        }

        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $results)));
        $now = CarbonImmutable::now();

        $products = SmProduct::query()
            ->whereIn('id', $ids)
            ->where('is_available', true)
            ->whereHas('store', fn ($storeQuery) => $storeQuery
                ->where('is_active', true)
                ->where(fn ($q) => $q
                    ->whereNull('suspension_until')
                    ->orWhere('suspension_until', '<=', $now)))
            ->with(['media', 'store'])
            ->get()
            ->keyBy('id');

        $ordered = collect($results)
            ->map(function (array $row) use ($products): ?SmProduct {
                $product = $products->get($row['id']);

                if (! $product instanceof SmProduct) {
                    return null;
                }

                $product->setAttribute('semantic_score', $row['score']);

                return $product;
            })
            ->filter(fn ($item): bool => $item instanceof SmProduct)
            ->values();

        $filtered = $this->filterSemanticResultsByTextSignal($ordered, $query);
        if ($filtered->isEmpty()) {
            return null;
        }

        return $this->paginateCollection($filtered, $perPage, $page, $request->query());
    }

    /**
     * Keep semantic recall, but reject unrelated vector matches unless there is
     * a strong semantic score or an exact/fuzzy lexical signal in the product.
     * Supports both Arabic and English spelling errors.
     *
     * @param  Collection<int, SmProduct>  $items
     */
    private function filterSemanticResultsByTextSignal(Collection $items, string $query): Collection
    {
        $tokens = $this->extractSearchTokens($query);

        if ($tokens === []) {
            return $items;
        }

        return $items
            ->filter(function (SmProduct $product) use ($tokens): bool {
                $score = $product->getAttribute('semantic_score');
                $numericScore = is_numeric($score) ? (float) $score : 0.0;

                if ($numericScore >= self::SEMANTIC_STRONG_SCORE_THRESHOLD) {
                    return true;
                }

                $searchableText = $this->normalizeSearchText(
                    implode(' ', array_filter([
                        (string) $product->name,
                        (string) ($product->description ?? ''),
                    ]))
                );

                $searchableTokens = $this->extractSearchTokens($searchableText);

                foreach ($tokens as $token) {
                    if (mb_strpos($searchableText, $token) !== false) {
                        return true;
                    }

                    foreach ($searchableTokens as $candidateToken) {
                        if ($this->isFuzzyTokenMatch($token, $candidateToken)) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    private function extractSearchTokens(string $query): array
    {
        $normalized = $this->normalizeSearchText($query);
        $parts = preg_split('/\s+/u', $normalized) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            $token = mb_trim($part);
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function normalizeSearchText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace('ـ', '', $text);
        $text = preg_replace('/[\p{Mn}\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text) ?? $text;
        $text = strtr($text, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ة' => 'ه',
            'ۀ' => 'ه',
            'ى' => 'ي',
            'ی' => 'ي',
            'ئ' => 'ي',
            'ؤ' => 'و',
            'ک' => 'ك',
            'گ' => 'ك',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
        ]);
        $text = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return mb_trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function isFuzzyTokenMatch(string $queryToken, string $candidateToken): bool
    {
        if ($queryToken === $candidateToken) {
            return true;
        }

        $queryLength = mb_strlen($queryToken);
        $candidateLength = mb_strlen($candidateToken);

        if ($queryLength < 2 || $candidateLength < 2) {
            return false;
        }

        $minLength = min($queryLength, $candidateLength);
        if ($minLength >= 3 && (mb_strpos($queryToken, $candidateToken) !== false || mb_strpos($candidateToken, $queryToken) !== false)) {
            return true;
        }

        $maxLength = max($queryLength, $candidateLength);
        $maxDistance = match (true) {
            $maxLength <= 4 => 1,
            $maxLength <= 8 => 2,
            default => 3,
        };

        if (abs($queryLength - $candidateLength) > $maxDistance) {
            return false;
        }

        $distance = $this->unicodeDamerauLevenshtein($queryToken, $candidateToken);
        if ($distance > $maxDistance) {
            return false;
        }

        if ($maxLength <= 4) {
            return $distance <= 1;
        }

        $similarity = 1 - ($distance / $maxLength);

        return $similarity >= 0.65;
    }

    private function unicodeDamerauLevenshtein(string $left, string $right): int
    {
        $leftChars = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rightChars = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $leftLength = count($leftChars);
        $rightLength = count($rightChars);

        if ($leftLength === 0) {
            return $rightLength;
        }

        if ($rightLength === 0) {
            return $leftLength;
        }

        $previousPrevious = null;
        $previous = range(0, $rightLength);

        for ($i = 1; $i <= $leftLength; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $rightLength; $j++) {
                $cost = $leftChars[$i - 1] === $rightChars[$j - 1] ? 0 : 1;

                $current[$j] = min(
                    $current[$j - 1] + 1,
                    $previous[$j] + 1,
                    $previous[$j - 1] + $cost,
                );

                if (
                    $previousPrevious !== null
                    && $i > 1
                    && $j > 1
                    && $leftChars[$i - 1] === $rightChars[$j - 2]
                    && $leftChars[$i - 2] === $rightChars[$j - 1]
                ) {
                    $current[$j] = min($current[$j], $previousPrevious[$j - 2] + 1);
                }
            }

            $previousPrevious = $previous;
            $previous = $current;
        }

        return $previous[$rightLength];
    }

    /**
     * @param  Collection<int, SmProduct>  $items
     * @param  array<string, mixed>  $query
     */
    private function paginateCollection(Collection $items, int $perPage, int $page, array $query): LengthAwarePaginator
    {
        $total = $items->count();
        $results = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => $query,
            ]
        );
    }
}
