<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Collection;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;

final class CleaningNeighborhoodResolver
{
    /**
     * @return Collection<int, CleaningNeighborhood>
     */
    public function list(?string $search = null, ?string $city = CleaningNeighborhoodNameNormalizer::ALEPPO_CITY, bool $activeOnly = true): Collection
    {
        $query = CleaningNeighborhood::query()
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->orderBy('id');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $canonicalCity = CleaningNeighborhoodNameNormalizer::canonicalCityName($city);
        if ($canonicalCity !== null) {
            $query->where('city_name', $canonicalCity);
        }

        $items = $query->get();

        if (! is_string($search) || mb_trim($search) === '') {
            return $items;
        }

        $needle = CleaningNeighborhoodNameNormalizer::normalize($search);
        $rawNeedle = mb_strtolower(CleaningNeighborhoodNameNormalizer::repairText($search));

        return $items->filter(function (CleaningNeighborhood $neighborhood) use ($needle, $rawNeedle): bool {
            foreach ($this->candidateKeys($neighborhood) as $candidate) {
                if (str_contains($candidate, $needle)) {
                    return true;
                }
            }

            foreach ($this->candidateValues($neighborhood) as $candidate) {
                if (str_contains(mb_strtolower($candidate), $rawNeedle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    public function findById(?int $id, bool $activeOnly = true): ?CleaningNeighborhood
    {
        if ($id === null) {
            return null;
        }

        return CleaningNeighborhood::query()
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->find($id);
    }

    public function resolve(?int $id, ?string $name, bool $activeOnly = true): ?CleaningNeighborhood
    {
        $fromId = $this->findById($id, $activeOnly);

        if ($fromId instanceof CleaningNeighborhood) {
            return $fromId;
        }

        if (! is_string($name) || mb_trim($name) === '') {
            return null;
        }

        return $this->matchText($name, activeOnly: $activeOnly);
    }

    public function matchText(string $text, ?string $city = CleaningNeighborhoodNameNormalizer::ALEPPO_CITY, bool $activeOnly = true): ?CleaningNeighborhood
    {
        $normalized = CleaningNeighborhoodNameNormalizer::normalize($text);

        if ($normalized === '') {
            return null;
        }

        $items = $this->list(city: $city, activeOnly: $activeOnly);

        $exact = $items->first(function (CleaningNeighborhood $neighborhood) use ($normalized): bool {
            return in_array($normalized, $this->candidateKeys($neighborhood), true);
        });

        if ($exact instanceof CleaningNeighborhood) {
            return $exact;
        }

        /** @var Collection<int, CleaningNeighborhood> $matches */
        $matches = $items->filter(function (CleaningNeighborhood $neighborhood) use ($normalized): bool {
            foreach ($this->candidateKeys($neighborhood) as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                if (str_contains($normalized, $candidate) || str_contains($candidate, $normalized)) {
                    return true;
                }
            }

            return false;
        })->sortByDesc(function (CleaningNeighborhood $neighborhood): int {
            return max(array_map(
                static fn (string $candidate): int => mb_strlen($candidate),
                $this->candidateKeys($neighborhood),
            ));
        })->values();

        $first = $matches->first();

        return $first instanceof CleaningNeighborhood ? $first : null;
    }

    /**
     * @return array<int, string>
     */
    private function candidateKeys(CleaningNeighborhood $neighborhood): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (?string $value): string => CleaningNeighborhoodNameNormalizer::normalize($value),
            [
                $neighborhood->name_ar,
                $neighborhood->name_en,
                $neighborhood->normalized_name,
                ...($neighborhood->aliases ?? []),
            ],
        ))));
    }

    /**
     * @return array<int, string>
     */
    private function candidateValues(CleaningNeighborhood $neighborhood): array
    {
        return array_values(array_unique(array_filter([
            CleaningNeighborhoodNameNormalizer::repairText($neighborhood->name_ar),
            CleaningNeighborhoodNameNormalizer::repairText($neighborhood->name_en),
            ...(is_array($neighborhood->aliases)
                ? array_map(
                    static fn ($alias): string => CleaningNeighborhoodNameNormalizer::repairText(is_string($alias) ? $alias : null),
                    $neighborhood->aliases
                )
                : []),
        ], static fn (string $value): bool => mb_trim($value) !== '')));
    }
}
