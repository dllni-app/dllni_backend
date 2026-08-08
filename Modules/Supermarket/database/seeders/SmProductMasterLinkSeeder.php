<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Modules\Supermarket\Models\SmProduct;

final class SmProductMasterLinkSeeder extends Seeder
{
    public function run(): void
    {
        /** @var Collection<int, MasterProduct> $masterProducts */
        $masterProducts = MasterProduct::query()->get();

        SmProduct::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $products) use (&$masterProducts): void {
                foreach ($products as $product) {
                    $masterProduct = $this->resolveMasterProduct($product, $masterProducts);

                    if ((int) $product->master_product_id === (int) $masterProduct->id) {
                        continue;
                    }

                    $product->forceFill([
                        'master_product_id' => $masterProduct->id,
                    ])->save();
                }
            });
    }

    /**
     * @param Collection<int, MasterProduct> $masterProducts
     */
    private function resolveMasterProduct(SmProduct $product, Collection &$masterProducts): MasterProduct
    {
        $normalizedProductName = $this->normalizeName($product->name);

        $exactMatch = $masterProducts->first(
            fn (MasterProduct $masterProduct): bool => $this->normalizeName($masterProduct->name) === $normalizedProductName
        );

        if ($exactMatch instanceof MasterProduct) {
            return $exactMatch;
        }

        $prefixMatch = $masterProducts
            ->filter(function (MasterProduct $masterProduct) use ($normalizedProductName): bool {
                $normalizedMasterName = $this->normalizeName($masterProduct->name);

                return $normalizedMasterName !== ''
                    && str_starts_with($normalizedProductName, $normalizedMasterName . ' ');
            })
            ->sortByDesc(fn (MasterProduct $masterProduct): int => mb_strlen($this->normalizeName($masterProduct->name)))
            ->first();

        if ($prefixMatch instanceof MasterProduct) {
            return $prefixMatch;
        }

        $masterProduct = MasterProduct::query()->firstOrCreate(
            ['name' => $this->canonicalName($product->name)],
            [
                'unit' => $this->inferUnit($product->name)->value,
                'brand' => null,
                'description' => $product->description,
                'is_active' => true,
            ]
        );

        $masterProducts->push($masterProduct);

        return $masterProduct;
    }

    private function normalizeName(string $name): string
    {
        $normalized = $this->canonicalName($name);
        $normalized = preg_replace(
            '/(\d+(?:[.,]\d+)?)\s+(كغ|كجم|غ|مل|لتر)/u',
            '$1$2',
            $normalized
        ) ?? $normalized;

        return mb_strtolower($normalized);
    }

    private function canonicalName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    }

    private function inferUnit(string $name): MasterProductUnit
    {
        $normalized = mb_strtolower($this->canonicalName($name));

        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:كغ|كجم|kg)/ui', $normalized) === 1) {
            return MasterProductUnit::Kilogram;
        }

        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:مل|ml)/ui', $normalized) === 1) {
            return MasterProductUnit::Milliliter;
        }

        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:لتر|l)/ui', $normalized) === 1) {
            return MasterProductUnit::Liter;
        }

        if (preg_match('/\d+(?:[.,]\d+)?\s*(?:غ|جم|g)/ui', $normalized) === 1) {
            return MasterProductUnit::Gram;
        }

        if (preg_match('/(?:حبة|حبات|عبوة|عبوات|كيس|أكياس|رول)/u', $normalized) === 1) {
            return MasterProductUnit::Pack;
        }

        return MasterProductUnit::Piece;
    }
}
