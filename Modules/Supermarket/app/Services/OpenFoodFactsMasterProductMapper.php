<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use App\Enums\MasterProductUnit;
use Carbon\CarbonImmutable;

final class OpenFoodFactsMasterProductMapper
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    public function map(array $record): ?array
    {
        $barcode = $this->normalizeString($record['code'] ?? null);
        if ($barcode === null) {
            return null;
        }

        $name = $this->resolveName($record);
        if ($name === null) {
            return null;
        }

        $brand = $this->truncate($this->normalizeString($record['brands'] ?? null), 255);
        $description = $this->resolveDescription($record, $name);
        $unit = $this->mapUnit($record);
        $countriesTags = $this->normalizeCountriesTags($record['countries_tags'] ?? null);
        $imageUrl = $this->resolvePrimaryImageUrl($record);
        $openFoodFactsUrl = 'https://world.openfoodfacts.org/product/'.$barcode;

        $payload = [
            'barcode' => $barcode,
            'name' => $name,
            'brand' => $brand,
            'description' => $description,
            'unit' => $unit->value,
            'is_active' => true,
            'openfoodfacts_url' => $openFoodFactsUrl,
            'openfoodfacts_last_modified_at' => $this->mapLastModifiedAt($record['last_modified_t'] ?? null),
            'openfoodfacts_countries_tags' => $countriesTags !== [] ? $countriesTags : null,
            'aliases' => $this->buildAliases($record, $name, $brand),
            'image_url' => $imageUrl,
        ];

        $payload['openfoodfacts_payload_hash'] = $this->buildPayloadHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function mapUnit(array $record): MasterProductUnit
    {
        $unitCandidate = $this->normalizeString($record['product_quantity_unit'] ?? null);
        $quantityText = $this->normalizeString($record['quantity'] ?? null) ?? '';

        if ($unitCandidate === null && $quantityText !== '') {
            if (preg_match('/([a-zA-Z]+)\s*$/u', $quantityText, $matches) === 1) {
                $unitCandidate = mb_strtolower($matches[1]);
            }
        }

        $normalizedUnit = $unitCandidate !== null
            ? mb_strtolower(preg_replace('/[^a-z]/', '', $unitCandidate) ?? $unitCandidate)
            : null;

        return match (true) {
            in_array($normalizedUnit, ['g', 'gr', 'gram', 'grams'], true) => MasterProductUnit::Gram,
            in_array($normalizedUnit, ['kg', 'kilo', 'kilogram', 'kilograms'], true) => MasterProductUnit::Kilogram,
            in_array($normalizedUnit, ['ml', 'milliliter', 'milliliters'], true) => MasterProductUnit::Milliliter,
            in_array($normalizedUnit, ['l', 'lt', 'liter', 'litre', 'liters', 'litres'], true) => MasterProductUnit::Liter,
            in_array($normalizedUnit, ['pc', 'pcs', 'piece', 'pieces', 'unit', 'units', 'item', 'items'], true) => MasterProductUnit::Piece,
            in_array($normalizedUnit, ['pack', 'packs', 'pkg', 'package', 'packages', 'bag', 'bags', 'box', 'boxes', 'bottle', 'bottles', 'can', 'cans', 'jar', 'jars', 'carton', 'packet', 'packets', 'container', 'containers', 'pouch', 'pouches', 'sachet', 'sachets', 'tray', 'tub', 'tubs'], true) => MasterProductUnit::Pack,
            $this->containsPackHint($quantityText) => MasterProductUnit::Pack,
            $this->containsPieceHint($quantityText) => MasterProductUnit::Piece,
            default => MasterProductUnit::Pack,
        };
    }

    /**
     * @param  array<int, string>|string|null  $countriesTags
     */
    public function countryMatches(array|string|null $countriesTags, string $countryTag): bool
    {
        $normalizedTarget = mb_strtolower(mb_trim($countryTag));
        if ($normalizedTarget === '') {
            return false;
        }

        return in_array($normalizedTarget, $this->normalizeCountriesTags($countriesTags), true);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function resolvePrimaryImageUrl(array $record): ?string
    {
        $selectedFront = $record['selected_images']['front'] ?? null;
        if (is_array($selectedFront)) {
            $display = $this->resolveLanguagePreferredValue($selectedFront['display'] ?? null);
            if ($display !== null) {
                return $display;
            }

            $small = $this->resolveLanguagePreferredValue($selectedFront['small'] ?? null);
            if ($small !== null) {
                return $small;
            }
        }

        foreach (['image_front_small_url', 'image_front_url', 'image_small_url', 'image_url'] as $key) {
            $url = $this->normalizeUrl($record[$key] ?? null);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveName(array $record): ?string
    {
        $arabic = $this->normalizeString($record['product_name_ar'] ?? null);
        if ($arabic !== null) {
            return $arabic;
        }

        $default = $this->normalizeString($record['product_name'] ?? null);
        if ($default !== null) {
            return $default;
        }

        $localizedEn = $this->normalizeString($record['product_name_en'] ?? null);
        if ($localizedEn !== null) {
            return $localizedEn;
        }

        foreach ($record as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (! str_starts_with($key, 'product_name_')) {
                continue;
            }

            if (in_array($key, ['product_name_ar', 'product_name', 'product_name_en'], true)) {
                continue;
            }

            $localized = $this->normalizeString($value);
            if ($localized !== null) {
                return $localized;
            }
        }

        return $this->normalizeString($record['generic_name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveDescription(array $record, string $name): ?string
    {
        foreach (['generic_name_ar', 'generic_name'] as $key) {
            $value = $this->normalizeString($record[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        $quantity = $this->normalizeString($record['quantity'] ?? null);
        if ($quantity !== null) {
            return mb_trim($name.' '.$quantity);
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private function buildAliases(array $record, string $canonicalName, ?string $brand): array
    {
        $candidates = [];
        $alternates = [
            $record['product_name_ar'] ?? null,
            $record['product_name'] ?? null,
            $record['product_name_en'] ?? null,
            $record['generic_name_ar'] ?? null,
            $record['generic_name'] ?? null,
        ];

        foreach ($record as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (! str_starts_with($key, 'product_name_') && ! str_starts_with($key, 'generic_name_')) {
                continue;
            }

            $alternates[] = $value;
        }

        foreach ($alternates as $alternate) {
            $alias = $this->normalizeString($alternate);
            if ($alias !== null) {
                $candidates[] = $alias;
            }
        }

        if ($brand !== null) {
            $candidates[] = mb_trim($brand.' '.$canonicalName);
            $candidates[] = mb_trim($canonicalName.' '.$brand);

            $baseCandidates = $candidates;
            foreach ($baseCandidates as $candidate) {
                $candidates[] = mb_trim($brand.' '.$candidate);
            }
        }

        return $this->deduplicateAliases($candidates, $canonicalName);
    }

    /**
     * @param  array<int, string>  $candidates
     * @return array<int, string>
     */
    private function deduplicateAliases(array $candidates, string $canonicalName): array
    {
        $seen = [mb_strtolower($canonicalName) => true];
        $aliases = [];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeString($candidate);
            if ($normalized === null) {
                continue;
            }

            $key = mb_strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $aliases[] = $normalized;
        }

        return $aliases;
    }

    private function containsPackHint(string $quantityText): bool
    {
        if ($quantityText === '') {
            return false;
        }

        return preg_match('/\b(pack|packs|box|boxes|bottle|bottles|bag|bags|can|cans|jar|jars|carton|packet|packets|container|containers|pouch|pouches|sachet|sachets|tray|tub|tubs)\b/i', $quantityText) === 1;
    }

    private function containsPieceHint(string $quantityText): bool
    {
        if ($quantityText === '') {
            return false;
        }

        return preg_match('/\b(piece|pieces|pc|pcs|unit|units|item|items)\b/i', $quantityText) === 1;
    }

    /**
     * @param  array<int, string>|string|null  $countriesTags
     * @return array<int, string>
     */
    private function normalizeCountriesTags(array|string|null $countriesTags): array
    {
        if (is_string($countriesTags)) {
            $countriesTags = explode(',', $countriesTags);
        }

        if (! is_array($countriesTags)) {
            return [];
        }

        $normalized = [];

        foreach ($countriesTags as $value) {
            $tag = $this->normalizeString($value);
            if ($tag === null) {
                continue;
            }

            $normalized[] = mb_strtolower($tag);
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  mixed  $value
     */
    private function resolveLanguagePreferredValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $this->normalizeUrl($value);
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['ar', 'en'] as $language) {
            $candidate = $this->normalizeUrl($value[$language] ?? null);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        foreach ($value as $nested) {
            $candidate = $this->resolveLanguagePreferredValue($nested);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $url = $this->normalizeString($value);
        if ($url === null) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(
                array_map(fn (mixed $item): ?string => $this->normalizeString($item), $value),
                fn (?string $item): bool => $item !== null
            ));
        }

        if (! is_scalar($value) && ! (is_object($value) && method_exists($value, '__toString'))) {
            return null;
        }

        $normalized = mb_trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length);
    }

    /**
     * @param  mixed  $value
     */
    private function mapLastModifiedAt(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestampUTC((int) $value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildPayloadHash(array $payload): string
    {
        $normalized = [
            'barcode' => $payload['barcode'] ?? null,
            'name' => $payload['name'] ?? null,
            'brand' => $payload['brand'] ?? null,
            'description' => $payload['description'] ?? null,
            'unit' => $payload['unit'] ?? null,
            'is_active' => $payload['is_active'] ?? null,
            'openfoodfacts_url' => $payload['openfoodfacts_url'] ?? null,
            'openfoodfacts_last_modified_at' => $payload['openfoodfacts_last_modified_at'] ?? null,
            'openfoodfacts_countries_tags' => $payload['openfoodfacts_countries_tags'] ?? [],
            'aliases' => $payload['aliases'] ?? [],
            'image_url' => $payload['image_url'] ?? null,
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
