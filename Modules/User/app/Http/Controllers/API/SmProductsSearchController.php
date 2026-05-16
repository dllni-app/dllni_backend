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

final class SmProductsSearchController
{
    private const float SEMANTIC_STRONG_SCORE_THRESHOLD = 0.9;

    public function __construct(
        private readonly SmSemanticProductSearchService $semanticSearchService,
    ) {}

    public function __invoke(DiscoverSupermarketProductsRequest $request): AnonymousResourceCollection
    {
        $query = $this->resolveSemanticQuery($request);

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

        if ($results === null) {
            return null;
        }

        if ($results === []) {
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

                $searchableText = $this->normalizeArabicText(
                    implode(' ', array_filter([
                        (string) $product->name,
                        (string) ($product->description ?? ''),
                    ]))
                );

                foreach ($tokens as $token) {
                    if (mb_strpos($searchableText, $token) !== false) {
                        return true;
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
        $normalized = $this->normalizeArabicText($query);
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

    private function normalizeArabicText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\p{Mn}\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return mb_trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
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
