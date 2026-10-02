<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Services\SmSemanticStoreSearchService;

final class SmartSearchStoreResolver
{
    public function __construct(
        private readonly SmSemanticStoreSearchService $semanticSearch,
    ) {}

    public function resolve(?string $name): ?SmStore
    {
        if ($name === null || mb_trim($name) === '') {
            return null;
        }

        $needle = $this->normalize($name);
        $stores = SmStore::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', now()))
            ->get();

        $exact = $stores->first(fn (SmStore $store): bool => $this->normalize($store->name) === $needle);
        if ($exact instanceof SmStore) {
            return $exact;
        }

        $fuzzy = $stores
            ->map(function (SmStore $store) use ($needle): array {
                similar_text($needle, $this->normalize($store->name), $score);

                return ['store' => $store, 'score' => (float) $score];
            })
            ->filter(fn (array $row): bool => $row['score'] >= 70)
            ->sortByDesc('score')
            ->first();

        if (is_array($fuzzy) && $fuzzy['store'] instanceof SmStore) {
            return $fuzzy['store'];
        }

        $semantic = $this->semanticSearch->search([
            'query' => $name,
            'top_k' => 5,
            'is_active' => true,
        ]);

        if (! is_array($semantic) || $semantic === []) {
            return null;
        }

        $id = (int) ($semantic[0]['id'] ?? 0);

        return $id > 0 ? SmStore::query()->where('is_active', true)->find($id) : null;
    }

    /**
     * @return list<SmStore>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = mb_trim($query);
        if ($query === '') {
            return [];
        }

        $semantic = $this->semanticSearch->search([
            'query' => $query,
            'top_k' => max(1, min(50, $limit)),
            'is_active' => true,
        ]);

        if (is_array($semantic) && $semantic !== []) {
            $ids = collect($semantic)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $stores = SmStore::query()
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', now()))
                ->get()
                ->keyBy('id');

            return collect($ids)
                ->map(fn (int $id): ?SmStore => $stores->get($id))
                ->filter(fn ($store): bool => $store instanceof SmStore)
                ->take($limit)
                ->values()
                ->all();
        }

        $needle = $this->normalize($query);

        return SmStore::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', now()))
            ->get()
            ->map(function (SmStore $store) use ($needle): array {
                $name = $this->normalize($store->name);
                $description = $this->normalize((string) ($store->description ?? ''));
                similar_text($needle, $name, $nameScore);
                similar_text($needle, $description, $descriptionScore);

                return [
                    'store' => $store,
                    'score' => max(
                        str_contains($name, $needle) || str_contains($needle, $name) ? 100.0 : $nameScore,
                        $descriptionScore * 0.75,
                    ),
                ];
            })
            ->filter(fn (array $row): bool => $row['score'] >= 55)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('store')
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(mb_trim($value));
        $value = str_replace(['سوبر ماركت', 'سوبرماركت', 'ماركت'], '', $value);
        $value = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ـ'], ['ا', 'ا', 'ا', 'ي', 'ه', ''], $value);
        $value = preg_replace('/[ًٌٍَُِّْـ]/u', '', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_trim($value);
    }
}
