<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Support\Facades\DB;

final class UserPopularSearchService
{
    public const string RESTAURANT = 'restaurant';
    public const string SUPERMARKET = 'supermarket';

    /**
     * @return list<string>
     */
    public function sections(): array
    {
        return [self::RESTAURANT, self::SUPERMARKET];
    }

    public function record(string $section, ?string $query): void
    {
        if (! in_array($section, $this->sections(), true) || ! is_string($query)) {
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
            'last_searched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('user_search_terms')
            ->where('section', $section)
            ->where('normalized_query', $normalized)
            ->update([
                'query' => $query,
                'searches_count' => DB::raw('searches_count + 1'),
                'last_searched_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @return list<string>
     */
    public function popular(string $section, int $limit = 6): array
    {
        if (! in_array($section, $this->sections(), true)) {
            return [];
        }

        $limit = max(1, min($limit, 20));

        return DB::table('user_search_terms')
            ->where('section', $section)
            ->where('searches_count', '>', 0)
            ->orderByDesc('searches_count')
            ->orderByDesc('last_searched_at')
            ->limit($limit)
            ->pluck('query')
            ->map(static fn ($value): string => (string) $value)
            ->values()
            ->all();
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
