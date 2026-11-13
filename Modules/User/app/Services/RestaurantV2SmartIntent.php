<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Modules\Resturants\Models\FoodDiscoveryCategory;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Translate natural language into the existing catalog's validated search constraints. */
final class RestaurantV2SmartIntent
{
    public function __construct(private SmartSearchIntentService $intent, private RestaurantSmartSearchService $semantic) {}

    public function resolve(string $query, array $filters, bool $coordinates): array
    {
        $normalized = RestaurantFoodSearchNormalizer::normalize(strtr($query, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
        $aliases = [
            'shawarma'=>['شاورما','شاورمه','shawarma'],
            'burger'=>['برغر','برجر','بركر','burger'],
            'pizza'=>['بيتزا','pizza'],
            'grill'=>['مشاوي','كباب','شيش طاووق','grill'],
            'fried-chicken'=>['بروستد','بروست','فروج','fried chicken'],
            'breakfast'=>['فطور','فلافل','فول','breakfast'],
            'pasta'=>['باستا','معكرونه','مكرونه','pasta'],
            'asian'=>['سوشي','اسيوي','sushi'],
            'desserts'=>['حلويات','كنافه','dessert'],
            'drinks'=>['مشروبات','عصير','drink'],
            'sides'=>['مقبلات','سلطات','salad'],
        ];
        $slug = null;
        foreach ($aliases as $key => $words) {
            foreach ($words as $word) {
                if (str_contains($normalized, $word)) {
                    $slug = $key;
                    break 2;
                }
            }
        }
        // Existing intent provider supports Gemini and a local fallback. Local
        // extraction protects explicit category and budget constraints regardless of model output.
        $parsed = $this->intent->interpret('restaurant', $query, 'ar');
        $notes = [];
        $budget = $this->budget($normalized);
        $parsedBudget = is_numeric($parsed['maxPrice'] ?? null) ? (float) $parsed['maxPrice'] : null;
        $budget ??= $parsedBudget;
        if ($budget !== null && $budget >= 0) {
            $filters['maxPrice'] = min($filters['maxPrice'] ?? $budget, $budget);
        }
        $category = $slug === null ? null : FoodDiscoveryCategory::query()->where('slug', $slug)->where('is_active', true)->first();
        if ($category !== null) {
            if (isset($filters['foodCategoryId']) && (int) $filters['foodCategoryId'] !== (int) $category->id) {
                $filters['candidateIds'] = [];
                $notes[] = 'التصنيف المطلوب يتعارض مع التصنيف المحدد.';
            } else {
                $filters['foodCategoryId'] = $category->id;
            }
        }
        $protein = null;
        $positiveText = preg_replace('/(?:بدون|من دون|بلا|ما في|مو فيها|مش فيها|without)\s+\S+/u', '', $normalized) ?? $normalized;
        if (preg_match('/دجاج|جاج|chicken/u', $positiveText)) {
            $protein = 'دجاج';
        } elseif (preg_match('/لحم|لحمه|beef/u', $positiveText)) {
            $protein = 'لحم';
        }
        $negative = (bool) preg_match('/(?:بدون|من دون|بلا|ما في|مو فيها|مش فيها|without)\s+\S+/u', $normalized);
        if ($negative || !empty($parsed['excludedAttributes']) || !empty($parsed['excludedItemTypes'])) {
            $excluded = $parsed['excludedAttributes'] ?? [];
            if (preg_match('/(?:بدون|من دون|بلا|ما في|مو فيها|مش فيها|without)\s+([^\s،,.]+)/u', $normalized, $match)) {
                $excluded[] = $match[1];
            }
            $excluded = array_values(array_unique(array_filter(array_map(fn ($term) => RestaurantFoodSearchNormalizer::normalize((string) $term), $excluded))));
            if ($excluded === [] || !empty($parsed['excludedItemTypes'])) {
                $filters['candidateIds'] = [];
                $notes[] = 'لا توجد بيانات موثقة للتحقق من جميع الاستبعادات المطلوبة.';
            } else {
                $filters['ingredientExclusions'] = $excluded;
                $notes[] = 'الاستبعاد حسب مكونات الوصفة التي أكدها المطعم؛ لا يضمن عدم التلوث المتبادل.';
            }
        }
        if ($protein !== null) {
            $filters['requiredTerm'] = $protein;
        }
        $servings = null;
        if (preg_match('/شخصين|شخصان|لشخصين/u', $normalized)) {
            $servings = 2;
        } elseif (preg_match('/(?:ل)?([0-9]+)\s*اشخاص/u', $normalized, $match)) {
            $servings = (int) $match[1];
        } elseif (preg_match('/للعيله|عائليه/u', $normalized)) {
            $servings = 3;
        }
        if ($servings !== null) {
            $filters['servings'] = max(1, $servings);
            $notes[] = 'تم البحث في عدد الحصص الموثق للوجبة.';
        }
        if (($parsed['nearby'] ?? false) && $coordinates && ($filters['sort'] ?? 'relevance') === 'relevance') {
            $filters['sort'] = 'nearest';
        } elseif (($parsed['lowPrice'] ?? false) && ($filters['sort'] ?? 'relevance') === 'relevance') {
            $filters['sort'] = 'price_asc';
        } elseif (($parsed['highRating'] ?? false) && ($filters['sort'] ?? 'relevance') === 'relevance') {
            $filters['sort'] = 'rating';
        } elseif (($parsed['fastPreparation'] ?? false) && ($filters['sort'] ?? 'relevance') === 'relevance') {
            $filters['sort'] = 'fastest';
        }
        if (is_numeric($parsed['maxPreparationMinutes'] ?? null)) {
            $filters['maxPreparationMinutes'] = (int) $parsed['maxPreparationMinutes'];
        }
        $text = mb_substr((string) ($parsed['searchText'] ?? $query), 0, 100);
        if ($category !== null) {
            // Category membership is stronger than loose substring or embedding similarity.
            $text = '';
        } elseif (!array_key_exists('candidateIds', $filters) && ($parsed['goal'] ?? '') !== 'find_restaurant') {
            try {
                $matches = $this->semantic->search($parsed, 50);
                $ids = collect($matches['bestMatches'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
                if ($ids !== []) {
                    $filters['candidateIds'] = $ids;
                    $text = '';
                }
            } catch (Throwable $e) {
                Log::warning('Restaurant V2 semantic search unavailable', ['type' => $e::class]);
            }
        }
        return ['text' => $text, 'filters' => $filters, 'interpretation' => [
            'searchText' => $text,
            'foodCategoryId' => $category?->id,
            'foodCategoryName' => $category?->name_ar,
            'requiredAttribute' => $protein,
            'servings' => $servings,
            'ingredientExclusions' => $filters['ingredientExclusions'] ?? [],
            'maxPrice' => $filters['maxPrice'] ?? null,
            'sort' => $filters['sort'] ?? 'relevance',
            'notes' => $notes,
            'source' => $parsed['source'] ?? 'fallback',
        ]];
    }

    public function budget(string $text): ?float
    {
        if (preg_match('/(?:تحت|اقل من|حدود|بحدود|ما يزيد عن|حتى)\s*([0-9]{1,3}(?:,[0-9]{3})+|[0-9]+(?:[.,][0-9]+)?)\s*(الف)?/u', $text, $m)) {
            $number = preg_match('/^[0-9]{1,3}(?:,[0-9]{3})+$/', $m[1]) ? str_replace(',', '', $m[1]) : str_replace(',', '.', $m[1]);
            return (float) $number * (!empty($m[2]) ? 1000 : 1);
        }
        if (preg_match('/(?:تحت|اقل من|حدود|بحدود|حتى)\s*(عشره|عشر|عشرين|ثلاثين|اربعين|خمسين|ستين|سبعين|ثمانين|تسعين|ميه|مايه|مئه|مية|مائة)\s*الف/u', $text, $m)) {
            return 1000.0 * match ($m[1]) {
                'عشره','عشر'=>10,'عشرين'=>20,'ثلاثين'=>30,'اربعين'=>40,'خمسين'=>50,'ستين'=>60,'سبعين'=>70,'ثمانين'=>80,'تسعين'=>90,default=>100,
            };
        }
        return null;
    }
}
