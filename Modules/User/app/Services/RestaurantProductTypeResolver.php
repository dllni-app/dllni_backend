<?php

declare(strict_types=1);

namespace Modules\User\Services;

final class RestaurantProductTypeResolver
{
    public function resolve(
        ?string $itemType,
        ?string $categoryName,
        ?string $productName = null,
        ?string $description = null,
    ): string {
        $itemType = is_string($itemType) ? mb_trim($itemType) : '';
        if ($itemType !== '') {
            return $itemType;
        }

        $text = $this->normalize(implode(' ', array_filter([
            $categoryName,
            $productName,
            $description,
        ])));

        $aliases = [
            'sandwich' => ['سندويش', 'ساندويش', 'سندويتش', 'sandwich'],
            'burger' => ['برغر', 'برجر', 'burger'],
            'pizza' => ['بيتزا', 'pizza'],
            'drink' => ['مشروب', 'مشروبات', 'عصير', 'كولا', 'بيبسي', 'drink', 'beverage'],
            'dessert' => ['حلويات', 'حلو', 'كيك', 'dessert', 'sweet'],
            'salad' => ['سلطه', 'سلطات', 'salad'],
            'combo' => ['كومبو', 'combo'],
            'family_meal' => ['عائلي', 'عائليه', 'family meal'],
            'breakfast' => ['فطور', 'افطار', 'breakfast'],
            'appetizer' => ['مقبلات', 'مقبل', 'appetizer', 'starter'],
            'side' => ['جانبي', 'جانبيه', 'سايد', 'side dish'],
            'meal' => [
                'وجبه',
                'وجبات',
                'طبق رئيسي',
                'الطبق الرئيسي',
                'اطباق رئيسيه',
                'الاطباق الرئيسيه',
                'main course',
                'main dish',
                'meal',
                'entree',
            ],
        ];

        foreach ($aliases as $type => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $this->normalize($word))) {
                    return $type;
                }
            }
        }

        return 'other';
    }

    public function matches(?string $requestedType, string $candidateType): bool
    {
        if ($requestedType === null || mb_trim($requestedType) === '') {
            return true;
        }

        $requestedType = mb_trim($requestedType);
        if ($requestedType === $candidateType) {
            return true;
        }

        return match ($requestedType) {
            'meal' => in_array($candidateType, ['meal', 'dish', 'combo', 'family_meal'], true),
            'dish' => in_array($candidateType, ['dish', 'meal'], true),
            default => false,
        };
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
