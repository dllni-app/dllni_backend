<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\FoodDiscoveryCategory;

final class ProductClassificationRules
{
    public const TYPES = ['meal', 'sandwich', 'burger', 'pizza', 'dish', 'combo', 'family_meal', 'appetizer', 'side', 'salad', 'dessert', 'drink', 'breakfast', 'other'];
    public const KINDS = ['main_dish', 'combo_meal', 'side_dish', 'beverage', 'dessert', 'standalone_sauce', 'other'];
    public const COMPONENTS = ['side', 'beverage', 'dessert', 'sauce'];

    private const COMPATIBLE = [
        'main_dish' => ['meal', 'sandwich', 'burger', 'pizza', 'dish', 'family_meal', 'breakfast'],
        'combo_meal' => ['meal', 'combo', 'family_meal'],
        'side_dish' => ['side', 'appetizer', 'salad'],
        'beverage' => ['drink'],
        'dessert' => ['dessert'],
        'standalone_sauce' => ['side', 'other'],
        'other' => ['other', 'dish'],
    ];

    public static function validate(array $attributes, bool $reviewed = false): void
    {
        $type = $attributes['item_type'] ?? null;
        $kind = $attributes['product_kind'] ?? null;
        $role = $attributes['catalog_role'] ?? 'independent';
        $family = $attributes['primary_food_category_id'] ?? null;
        $servings = $attributes['servings'] ?? null;
        $components = $attributes['included_components'] ?? null;
        $errors = [];

        if ($type !== null && !in_array($type, self::TYPES, true)) {
            $errors['itemType'] = 'Invalid presentation.';
        }
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            $errors['productKind'] = 'Invalid product role.';
        }
        if ($type !== null && $kind !== null && !in_array($type, self::COMPATIBLE[$kind] ?? [], true)) {
            $errors['itemType'] = 'Presentation is not compatible with the product role.';
        }
        if (!in_array($role, ['independent', 'modifier_only'], true)) {
            $errors['catalogRole'] = 'Invalid catalog role.';
        }
        if ($role === 'modifier_only' && ($family !== null || ($kind !== null && $kind !== 'other'))) {
            $errors['catalogRole'] = 'A modifier-only item cannot have a standalone food family or role.';
        }
        if ($servings !== null && (!is_numeric($servings) || (int) $servings != $servings || $servings < 1 || $servings > 50
            || ($type === 'family_meal' && $servings < 2))) {
            $errors['servings'] = 'Enter a valid portion count (at least two for a family meal), or leave it unknown.';
        }
        if ($components !== null && (!is_array($components) || array_diff($components, self::COMPONENTS) !== []
            || count(array_unique($components)) !== count($components))) {
            $errors['includedComponents'] = 'Invalid or duplicate included components.';
        }
        if ($reviewed && $role === 'independent') {
            if ($kind === null || $type === null) {
                $errors['itemType'] = 'A reviewed standalone product requires explicit presentation and role.';
            }
            if ($family === null || !FoodDiscoveryCategory::query()->whereKey($family)->where('is_active', true)->exists()) {
                $errors['primaryFoodCategoryId'] = 'Choose an active global food family before approval.';
            }
            if ($kind === 'combo_meal' && ($components === null || $components === [])) {
                $errors['includedComponents'] = 'A reviewed combo needs known included components.';
            }
        } elseif ($family !== null && !FoodDiscoveryCategory::query()->whereKey($family)->where('is_active', true)->exists()) {
            $errors['primaryFoodCategoryId'] = 'Choose an active global food family.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
