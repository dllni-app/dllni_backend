<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Services\GeminiProductService;
use Throwable;

final class SmartSearchIntentService
{
    public function __construct(
        private readonly GeminiProductService $gemini,
        private readonly SmartSearchUnitNormalizer $units,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function interpret(string $section, string $query, ?string $locale = 'ar'): array
    {
        $query = mb_trim($query);
        $payload = null;

        try {
            $payload = $this->gemini->interpretSmartSearch($section, $query, $locale);
        } catch (Throwable) {
            $payload = null;
        }

        if (is_array($payload) && ($payload['goal'] ?? '') !== '') {
            $normalized = $section === 'restaurant'
                ? $this->normalizeRestaurant($payload, $query)
                : $this->normalizeSupermarket($payload, $query);
            $normalized['source'] = 'gemini';

            return $normalized;
        }

        $fallback = $section === 'restaurant'
            ? $this->fallbackRestaurant($query)
            : $this->fallbackSupermarket($query);
        $fallback['source'] = 'fallback';

        return $fallback;
    }

    /** @return array<string, mixed> */
    private function normalizeRestaurant(array $payload, string $query): array
    {
        return [
            'goal' => $this->string($payload['goal'] ?? null, 'find_food'),
            'searchText' => $this->string($payload['searchText'] ?? null, $query),
            'itemType' => $this->nullableString($payload['itemType'] ?? null),
            'concepts' => $this->strings($payload['concepts'] ?? []),
            'attributes' => $this->strings($payload['attributes'] ?? []),
            'excludedAttributes' => $this->strings($payload['excludedAttributes'] ?? []),
            'excludedItemTypes' => $this->strings($payload['excludedItemTypes'] ?? []),
            'restaurantName' => $this->nullableString($payload['restaurantName'] ?? null),
            'cuisine' => $this->nullableString($payload['cuisine'] ?? null),
            'maxPrice' => $this->positiveNumber($payload['maxPrice'] ?? null),
            'maxPreparationMinutes' => $this->positiveInt($payload['maxPreparationMinutes'] ?? null),
            'minimumRating' => $this->positiveNumber($payload['minimumRating'] ?? null),
            'fastPreparation' => (bool) ($payload['fastPreparation'] ?? false),
            'lowPrice' => (bool) ($payload['lowPrice'] ?? false),
            'highRating' => (bool) ($payload['highRating'] ?? false),
            'nearby' => (bool) ($payload['nearby'] ?? false),
            'confidence' => $this->confidence($payload['confidence'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeSupermarket(array $payload, string $query): array
    {
        return [
            'goal' => $this->string($payload['goal'] ?? null, 'direct_product_search'),
            'searchText' => $this->string($payload['searchText'] ?? null, $query),
            'preferredStoreName' => $this->nullableString($payload['preferredStoreName'] ?? null),
            'storeStrict' => (bool) ($payload['storeStrict'] ?? false),
            'sameStoreRequired' => (bool) ($payload['sameStoreRequired'] ?? false),
            'recipeName' => $this->nullableString($payload['recipeName'] ?? null),
            'contextName' => $this->nullableString($payload['contextName'] ?? null),
            'servings' => $this->positiveInt($payload['servings'] ?? null),
            'alreadyHave' => $this->strings($payload['alreadyHave'] ?? []),
            'excludedIngredients' => $this->strings($payload['excludedIngredients'] ?? []),
            'items' => $this->normalizeItems($payload['items'] ?? []),
            'inferredIngredients' => $this->normalizeItems($payload['inferredIngredients'] ?? []),
            'confidence' => $this->confidence($payload['confidence'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackRestaurant(string $query): array
    {
        $normalized = $this->normalizeArabic($query);
        $itemType = null;
        $typeAliases = [
            'meal' => ['وجبه', 'وجبة'],
            'sandwich' => ['سندويشه', 'سندويشة', 'ساندويش', 'سندويتش'],
            'burger' => ['برغر', 'برجر', 'burger'],
            'pizza' => ['بيتزا', 'pizza'],
            'drink' => ['مشروب', 'عصير', 'كولا'],
            'dessert' => ['حلويات', 'حلو', 'ديسرت'],
            'salad' => ['سلطه', 'سلطة'],
        ];

        foreach ($typeAliases as $type => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($normalized, $this->normalizeArabic($alias))) {
                    $itemType = $type;
                    break 2;
                }
            }
        }

        $excludedItemTypes = [];
        foreach ($typeAliases as $type => $aliases) {
            foreach ($aliases as $alias) {
                $a = preg_quote($this->normalizeArabic($alias), '/');
                if (preg_match('/(?:مو|مش|ما بدي)\s+[^،,.]{0,12}'.$a.'/u', $normalized)) {
                    $excludedItemTypes[] = $type;
                    break;
                }
            }
        }

        $fast = (bool) preg_match('/سريع|بسرعه|ما تطول|تخلص بسرعه|جاهز بسرعه/u', $normalized);
        $lowPrice = (bool) preg_match('/رخيص|مو غالي|مش غالي|اقتصادي/u', $normalized);
        $highRating = (bool) preg_match('/تقييم عالي|احسن|افضل|ممتاز/u', $normalized);

        $maxPrep = null;
        if (preg_match('/(?:اقل من|تحت)\s*(\d+)\s*دقيق/u', $normalized, $m)) {
            $maxPrep = (int) $m[1];
        }

        $attributes = [];
        foreach (['حار', 'سبايسي', 'مقرمش', 'كريسبي', 'مشوي', 'مقلي', 'خفيف'] as $attribute) {
            if (str_contains($normalized, $this->normalizeArabic($attribute))) {
                $attributes[] = $attribute;
            }
        }

        return [
            'goal' => str_contains($normalized, 'مطعم') && $itemType === null ? 'find_restaurant' : 'find_food',
            'searchText' => $query,
            'itemType' => $itemType,
            'concepts' => [],
            'attributes' => $attributes,
            'excludedAttributes' => [],
            'excludedItemTypes' => array_values(array_unique($excludedItemTypes)),
            'restaurantName' => null,
            'cuisine' => null,
            'maxPrice' => null,
            'maxPreparationMinutes' => $maxPrep,
            'minimumRating' => null,
            'fastPreparation' => $fast,
            'lowPrice' => $lowPrice,
            'highRating' => $highRating,
            'nearby' => (bool) preg_match('/قريب|قربي|حوالي/u', $normalized),
            'confidence' => 0.45,
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackSupermarket(string $query): array
    {
        $normalized = $this->normalizeArabic($query);
        $isPrepare = (bool) preg_match('/(?:بدي\s+)?(?:حضر|احضر|اعمل|اطبخ|جهز)\s+/u', $normalized)
            && ! str_contains($normalized, 'جاهز');

        $context = null;
        $contextAliases = [
            'فطور' => 'فطور',
            'افطار' => 'فطور',
            'شواء' => 'شواء',
            'مشاوي' => 'شواء',
            'حفله' => 'حفلة',
            'عيد ميلاد' => 'حفلة',
            'عزيمه' => 'حفلة',
            'اغراض اسبوع' => 'مؤونة أسبوع',
            'تكفيني اسبوع' => 'مؤونة أسبوع',
        ];
        foreach ($contextAliases as $needle => $resolved) {
            if (str_contains($normalized, $needle)) {
                $context = $resolved;
                break;
            }
        }

        $recipe = null;
        if ($isPrepare && $context === null && preg_match(
            '/(?:حضر|احضر|اعمل|اطبخ|جهز)\s+(.+?)(?=\s+(?:من عند|من سوبرماركت|من ماركت)|[،,.]|$)/u',
            $normalized,
            $m,
        )) {
            $recipe = mb_trim($m[1]);
        }

        $store = null;
        if (preg_match('/(?:من عند|من سوبرماركت|من ماركت)\s+([^،,.]+)/u', $normalized, $m)) {
            $store = mb_trim($m[1]);
        }

        $findStore = ! $isPrepare
            && $context === null
            && (bool) preg_match('/(?:بدي|دورلي|وين|عطيني)\s+(?:سوبرماركت|ماركت|متجر)/u', $normalized);

        $goal = $findStore
            ? 'find_store'
            : ($context !== null
                ? 'prepare_context'
                : ($isPrepare ? 'prepare_recipe' : 'multi_product_search'));

        return [
            'goal' => $goal,
            'searchText' => $query,
            'preferredStoreName' => $store,
            'storeStrict' => $store !== null && (bool) preg_match('/فقط|بس من/u', $normalized),
            'sameStoreRequired' => (bool) preg_match('/نفس (?:السوبرماركت|المحل|المتجر)|كل(?:هم|ها) من نفس/u', $normalized),
            'recipeName' => $recipe,
            'contextName' => $context,
            'servings' => $this->extractServings($normalized),
            'alreadyHave' => [],
            'excludedIngredients' => [],
            'items' => ($isPrepare || $context !== null || $findStore) ? [] : $this->fallbackItems($query),
            'inferredIngredients' => [],
            'confidence' => 0.4,
        ];
    }

    /** @return list<array{query:string, quantity:float|null, unit:string|null}> */
    private function normalizeItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $query = $this->nullableString($item['query'] ?? null);
            if ($query === null) {
                continue;
            }
            $quantity = $this->positiveNumber($item['quantity'] ?? null);
            $unit = $this->units->normalize($this->nullableString($item['unit'] ?? null));
            $normalized[] = ['query' => $query, 'quantity' => $quantity, 'unit' => $unit];
        }

        return $normalized;
    }

    /** @return list<array{query:string, quantity:float|null, unit:string|null}> */
    private function fallbackItems(string $query): array
    {
        $parts = preg_split('/[,،\n]+|\s+و\s+/u', $query) ?: [];
        $items = [];

        foreach ($parts as $part) {
            $part = mb_trim($part);
            if ($part === '') {
                continue;
            }

            $part = preg_replace(
                '/\s+(?:من عند|من سوبرماركت|من ماركت)\s+.+$/u',
                '',
                $part,
            ) ?? $part;
            $part = mb_trim($part);
            if ($part === '') {
                continue;
            }

            $quantity = null;
            $unit = null;
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(كيلو\s*غرام|كيلوغرام|كيلو|كغ|غرام|غ|ليتر|لتر|مل|حبه|حبة|قطعه|قطعة|kg|g|l|ml)\b/ui', $part, $m)) {
                $quantity = (float) str_replace(',', '.', $m[1]);
                $unit = $this->units->normalize($m[2]);
                $part = mb_trim(str_replace($m[0], '', $part));
            }

            $part = preg_replace('/^(?:بدي|اريد|عايز)\s+/u', '', $part) ?? $part;
            if ($part !== '') {
                $items[] = ['query' => $part, 'quantity' => $quantity, 'unit' => $unit];
            }
        }

        return $items;
    }

    private function extractServings(string $query): ?int
    {
        if (preg_match('/(?:ل|يكفي)\s*(\d+)\s*(?:اشخاص|اشخاص|شخص)/u', $query, $m)) {
            return max(1, (int) $m[1]);
        }

        return null;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($item): ?string => $this->nullableString($item), $value)));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value !== '' ? $value : null;
    }

    private function string(mixed $value, string $fallback): string
    {
        return $this->nullableString($value) ?? $fallback;
    }

    private function positiveNumber(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return (float) $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.5;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function normalizeArabic(string $value): string
    {
        $value = mb_strtolower(mb_trim($value));
        $value = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ـ'], ['ا', 'ا', 'ا', 'ي', 'ه', ''], $value);
        $value = preg_replace('/[ًٌٍَُِّْـ]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_trim($value);
    }
}
