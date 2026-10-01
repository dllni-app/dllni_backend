<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\Recipe;

final class SmartSearchRecipeResolver
{
    /**
     * @param  array<string, mixed>  $intent
     * @return array{recipe:array<string,mixed>|null,items:list<array<string,mixed>>,source:string}
     */
    public function resolve(array $intent): array
    {
        $recipeName = is_string($intent['recipeName'] ?? null) ? mb_trim($intent['recipeName']) : '';
        if ($recipeName === '') {
            return [
                'recipe' => null,
                'items' => $this->filterItems($intent['inferredIngredients'] ?? [], $intent),
                'source' => 'inferred',
            ];
        }

        $recipe = $this->findRecipe($recipeName);
        if ($recipe === null) {
            return [
                'recipe' => [
                    'name' => $recipeName,
                    'canonicalName' => $recipeName,
                    'servings' => $intent['servings'] ?? null,
                ],
                'items' => $this->filterItems($intent['inferredIngredients'] ?? [], $intent),
                'source' => 'gemini',
            ];
        }

        $defaultServings = max(1, (int) ($recipe->servings ?? 1));
        $requestedServings = is_numeric($intent['servings'] ?? null)
            ? max(1, (int) $intent['servings'])
            : $defaultServings;
        $scale = $requestedServings / $defaultServings;

        $items = $recipe->ingredients
            ->map(function ($ingredient) use ($scale): ?array {
                $master = $ingredient->masterProduct;
                if ($master === null) {
                    return null;
                }

                $unit = $ingredient->unit?->value ?? (is_string($ingredient->unit) ? $ingredient->unit : null);

                return [
                    'query' => $master->name,
                    'ingredientKey' => 'master_product_'.$master->id,
                    'masterProductId' => $master->id,
                    'quantity' => $ingredient->quantity !== null
                        ? round((float) $ingredient->quantity * $scale, 3)
                        : null,
                    'unit' => $unit,
                    'required' => ! (bool) $ingredient->is_optional,
                    'priority' => (bool) $ingredient->is_optional ? 200 : 100,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'recipe' => [
                'id' => $recipe->id,
                'name' => $recipeName,
                'canonicalName' => $recipe->name,
                'servings' => $requestedServings,
                'defaultServings' => $defaultServings,
            ],
            'items' => $this->filterItems($items, $intent),
            'source' => 'catalog',
        ];
    }

    private function findRecipe(string $name): ?Recipe
    {
        $needle = $this->normalize($name);
        $recipes = Recipe::query()
            ->where('is_active', true)
            ->with(['aliases', 'ingredients.masterProduct'])
            ->get();

        $exact = $recipes->first(function (Recipe $recipe) use ($needle): bool {
            if ($this->normalize($recipe->name) === $needle || $this->normalize($recipe->slug) === $needle) {
                return true;
            }

            return $recipe->aliases->contains(
                fn ($alias): bool => $this->normalize((string) $alias->alias) === $needle
            );
        });

        if ($exact instanceof Recipe) {
            return $exact;
        }

        return $recipes
            ->map(function (Recipe $recipe) use ($needle): array {
                $names = collect([$recipe->name, $recipe->slug])
                    ->merge($recipe->aliases->pluck('alias'))
                    ->map(fn ($value): string => $this->normalize((string) $value));

                $score = $names->max(function (string $candidate) use ($needle): float {
                    similar_text($needle, $candidate, $percent);

                    return $percent;
                });

                return ['recipe' => $recipe, 'score' => (float) $score];
            })
            ->filter(fn (array $row): bool => $row['score'] >= 72)
            ->sortByDesc('score')
            ->value('recipe');
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<array<string, mixed>>
     */
    private function filterItems(mixed $items, array $intent): array
    {
        if (! is_array($items)) {
            return [];
        }

        $alreadyHave = $this->normalizedSet($intent['alreadyHave'] ?? []);
        $excluded = $this->normalizedSet($intent['excludedIngredients'] ?? []);

        return collect($items)
            ->filter(fn ($item): bool => is_array($item) && is_string($item['query'] ?? null))
            ->reject(function (array $item) use ($alreadyHave, $excluded): bool {
                $query = $this->normalize((string) $item['query']);

                return $this->matchesSet($query, $alreadyHave) || $this->matchesSet($query, $excluded);
            })
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function normalizedSet(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item): string => is_string($item) ? $this->normalize($item) : '',
            $items,
        )));
    }

    /** @param list<string> $set */
    private function matchesSet(string $needle, array $set): bool
    {
        foreach ($set as $candidate) {
            if ($needle === $candidate || str_contains($needle, $candidate) || str_contains($candidate, $needle)) {
                return true;
            }
        }

        return false;
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
