<?php

declare(strict_types=1);

namespace Modules\User\Services;

final class SmartSearchContextResolver
{
    /**
     * @var array<string, array{aliases:list<string>, items:list<string>}>
     */
    private const array TEMPLATES = [
        'breakfast' => [
            'aliases' => ['فطور', 'افطار', 'فطور صباحي', 'breakfast'],
            'items' => ['خبز', 'لبنة', 'جبنة', 'بيض', 'حليب', 'شاي'],
        ],
        'barbecue' => [
            'aliases' => ['شواء', 'مشاوي', 'باربكيو', 'barbecue', 'bbq'],
            'items' => ['لحم', 'دجاج', 'خبز', 'طماطم', 'بصل', 'فحم شواء'],
        ],
        'baking' => [
            'aliases' => ['كيك', 'حلويات', 'خبز كيك', 'baking'],
            'items' => ['طحين', 'سكر', 'بيض', 'حليب', 'زبدة'],
        ],
        'party' => [
            'aliases' => ['حفلة', 'عيد ميلاد', 'عزيمة', 'ضيوف', 'party'],
            'items' => ['مياه', 'عصير', 'مشروبات غازية', 'شيبس', 'مكسرات'],
        ],
        'weekly_stock' => [
            'aliases' => ['مونة اسبوع', 'مؤونة اسبوع', 'اغراض اسبوع', 'تكفيني اسبوع', 'weekly groceries'],
            'items' => ['رز', 'سكر', 'زيت', 'حليب', 'خبز', 'عدس'],
        ],
    ];

    /**
     * @return array{key:string,name:string,items:list<array<string,mixed>>}|null
     */
    public function resolve(?string $context): ?array
    {
        if ($context === null || mb_trim($context) === '') {
            return null;
        }

        $needle = $this->normalize($context);
        $bestKey = null;
        $bestScore = 0.0;

        foreach (self::TEMPLATES as $key => $template) {
            foreach ($template['aliases'] as $alias) {
                $candidate = $this->normalize($alias);
                if ($needle === $candidate || str_contains($needle, $candidate) || str_contains($candidate, $needle)) {
                    $bestKey = $key;
                    $bestScore = 100.0;
                    break 2;
                }

                similar_text($needle, $candidate, $score);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestKey = $key;
                }
            }
        }

        if ($bestKey === null || $bestScore < 68) {
            return null;
        }

        $template = self::TEMPLATES[$bestKey];

        return [
            'key' => $bestKey,
            'name' => $context,
            'items' => array_map(
                static fn (string $query): array => [
                    'query' => $query,
                    'quantity' => null,
                    'unit' => null,
                    'required' => true,
                    'priority' => 100,
                ],
                $template['items'],
            ),
        ];
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
