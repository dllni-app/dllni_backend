<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;

final class UserPopularSearchService
{
    public const RESTAURANT = 'restaurant';
    public const SUPERMARKET = 'supermarket';

    public const PRODUCTS = 'products';
    public const MERCHANTS = 'merchants';

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        return [self::RESTAURANT, self::SUPERMARKET];
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [self::PRODUCTS, self::MERCHANTS];
    }

    public function record(string $section, ?string $query, ?string $filter = null): void
    {
        if (! in_array($section, $this->sections(), true) || ! is_string($query)) {
            return;
        }

        if ($filter !== null && ! in_array($filter, $this->filters(), true)) {
            return;
        }

        $query = mb_trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return;
        }

        $query = mb_substr($query, 0, 255);
        $normalized = $this->normalize($query);
        if ($normalized === '') {
            return;
        }

        $now = now();

        DB::table('user_search_terms')->insertOrIgnore([
            'section' => $section,
            'query' => $query,
            'normalized_query' => $normalized,
            'searches_count' => 0,
            'product_searches_count' => 0,
            'merchant_searches_count' => 0,
            'last_searched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $updates = [
            'query' => $query,
            'searches_count' => DB::raw('searches_count + 1'),
            'last_searched_at' => $now,
            'updated_at' => $now,
        ];

        if ($filter !== null) {
            $countColumn = $this->countColumn($filter);
            $updates[$countColumn] = DB::raw("{$countColumn} + 1");
        }

        DB::table('user_search_terms')
            ->where('section', $section)
            ->where('normalized_query', $normalized)
            ->update($updates);
    }

    /**
     * @return list<string>
     */
    public function popular(string $section, ?string $filter = null, int $limit = 6): array
    {
        if (! in_array($section, $this->sections(), true)) {
            return [];
        }

        if ($filter !== null && ! in_array($filter, $this->filters(), true)) {
            return [];
        }

        $limit = max(1, min($limit, 20));
        $countColumn = $filter === null ? 'searches_count' : $this->countColumn($filter);

        return DB::table('user_search_terms')
            ->where('section', $section)
            ->where($countColumn, '>', 0)
            ->orderByDesc($countColumn)
            ->orderByDesc('last_searched_at')
            ->limit($limit)
            ->pluck('query')
            ->map(static fn ($value): string => (string) $value)
            ->values()
            ->all();
    }

    private function countColumn(string $filter): string
    {
        return match ($filter) {
            self::PRODUCTS => 'product_searches_count',
            self::MERCHANTS => 'merchant_searches_count',
        };
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\p{Mn}\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ى', 'ي', $text);
        $text = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return mb_trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
