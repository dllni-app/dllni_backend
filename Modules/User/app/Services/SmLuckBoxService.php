<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Supermarket\Models\SmCategory;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class SmLuckBoxService
{
    /**
     * @return array{restrictions: list<array{value: string, labelAr: string}>, categoryTypes: list<array{id: int, name: string}>}
     */
    public function options(): array
    {
        $categoryTypes = SmCategory::query()
            ->where('is_active', true)
            ->whereHas('store', fn (Builder $q) => $q->where('is_active', true))
            ->selectRaw('MIN(id) as id, name')
            ->groupBy('name')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
            ])
            ->values()
            ->all();

        return [
            'restrictions' => [
                ['value' => 'vegetarian', 'labelAr' => 'نباتي'],
                ['value' => 'gluten_free', 'labelAr' => 'خالي من الغلوتين'],
                ['value' => 'nut_free', 'labelAr' => 'خالي من المكسرات'],
                ['value' => 'dairy_free', 'labelAr' => 'خالي من الألبان'],
                ['value' => 'halal_friendly', 'labelAr' => 'مناسب للحلال'],
            ],
            'categoryTypes' => $categoryTypes,
        ];
    }

    /**
     * @param  list<string>  $restrictions
     * @return array{budget: array{groupSize: int, budgetPerPerson: float, total: float}, bundles: list<array<string, mixed>>}
     */
    public function suggest(
        int $groupSize,
        float $budgetPerPerson,
        array $restrictions = [],
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $searchRadiusKm = null,
        ?int $categoryId = null,
        ?int $storeId = null,
    ): array {
        $budgetTotal = round($groupSize * $budgetPerPerson, 2);

        $storesQuery = SmStore::query()
            ->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('suspension_until')->orWhere('suspension_until', '<=', now());
            });

        if ($storeId !== null) {
            $storesQuery->where('id', $storeId);
        }

        if (is_numeric($latitude) && is_numeric($longitude) && is_numeric($searchRadiusKm)) {
            $lat = (float) $latitude;
            $lng = (float) $longitude;
            $storesQuery
                ->select('sm_stores.*')
                ->selectRaw(
                    '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distance_km',
                    [$lat, $lng, $lat]
                )
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->havingRaw('distance_km <= ?', [(float) $searchRadiusKm]);
        }

        $stores = $storesQuery
            ->orderByDesc('is_featured')
            ->orderByDesc('average_rating')
            ->limit(40)
            ->get();

        $bestValue = null;
        $fastest = null;
        $balanced = null;

        foreach ($stores as $store) {
            if (! $store instanceof SmStore) {
                continue;
            }

            $products = $this->productsForStore($store->id, $restrictions, $categoryId);
            if ($products->isEmpty()) {
                continue;
            }

            $bv = $this->buildBestValueBundle($store, $products, $budgetTotal);
            if ($bv !== null && ($bestValue === null || $bv['score'] > $bestValue['score'])) {
                $bestValue = $bv;
            }

            $ft = $this->buildFastestBundle($store, $products, $budgetTotal);
            if ($ft !== null && ($fastest === null || $ft['score'] > $fastest['score'])) {
                $fastest = $ft;
            }

            $bl = $this->buildBalancedBundle($store, $products, $budgetTotal);
            if ($bl !== null && ($balanced === null || $bl['score'] > $balanced['score'])) {
                $balanced = $bl;
            }
        }

        $bundles = array_values(array_filter([
            $bestValue ? $this->formatBundle('best_value', 'الأوفر', $bestValue) : null,
            $fastest ? $this->formatBundle('fastest', 'الأسرع', $fastest) : null,
            $balanced ? $this->formatBundle('balanced', 'المتوازن', $balanced) : null,
        ]));

        return [
            'budget' => [
                'groupSize' => $groupSize,
                'budgetPerPerson' => $budgetPerPerson,
                'total' => $budgetTotal,
            ],
            'bundles' => $bundles,
        ];
    }

    /**
     * @param  list<string>  $restrictions
     * @return Collection<int, SmProduct>
     */
    private function productsForStore(int $storeId, array $restrictions, ?int $categoryId): Collection
    {
        $query = SmProduct::query()
            ->where('store_id', $storeId)
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0);

        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        $this->applyRestrictions($query, $restrictions);

        return $query->with(['category', 'media'])->orderBy('name')->get();
    }

    /**
     * @param  list<string>  $restrictions
     */
    private function applyRestrictions(Builder $query, array $restrictions): void
    {
        foreach ($restrictions as $restriction) {
            $patterns = match ($restriction) {
                'vegetarian' => ['meat', 'beef', 'chicken', 'fish', 'salmon', 'pork', 'lamb', 'turkey', 'لحم', 'دجاج', 'سمك'],
                'gluten_free' => ['bread', 'wheat', 'pasta', 'flour', 'gluten', 'barley', 'خبز', 'قمح'],
                'nut_free' => ['nut', 'almond', 'peanut', 'cashew', 'pistachio', 'جوز', 'لوز', 'فول سوداني'],
                'dairy_free' => ['milk', 'cheese', 'cream', 'yogurt', 'butter', 'dairy', 'حليب', 'جبن', 'زبدة'],
                'halal_friendly' => ['pork', 'bacon', 'ham', 'wine', 'beer', 'alcohol', 'خمر', 'لحم خنزير'],
                default => [],
            };

            foreach ($patterns as $word) {
                $like = '%'.$word.'%';
                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('name', 'not like', $like)
                        ->where(function (Builder $d) use ($like): void {
                            $d->whereNull('description')->orWhere('description', 'not like', $like);
                        });
                });
            }
        }
    }

    /**
     * @return array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}
     */
    private function lineItemFromProduct(SmProduct $product, float $unitPrice): array
    {
        $url = $product->getFirstMediaUrl(SmProduct::IMAGE_COLLECTION);

        return [
            'productId' => $product->id,
            'name' => $product->name,
            'quantity' => 1,
            'unitPrice' => $unitPrice,
            'lineTotal' => $unitPrice,
            'imageUrl' => $url !== '' ? $url : null,
        ];
    }

    private function effectivePrice(SmProduct $p): float
    {
        $discounted = $p->discounted_price;

        return (float) (($discounted !== null && (float) $discounted > 0) ? $discounted : $p->price);
    }

    /**
     * @param  Collection<int, SmProduct>  $products
     * @return array{store: SmStore, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}|null
     */
    private function buildBestValueBundle(SmStore $store, Collection $products, float $budgetTotal): ?array
    {
        $sorted = $products->sortBy(fn (SmProduct $p) => $this->effectivePrice($p))->values();
        $lineItems = [];
        $total = 0.0;

        foreach ($sorted as $product) {
            $price = $this->effectivePrice($product);
            if ($price <= 0) {
                continue;
            }
            if ($total + $price > $budgetTotal + 0.0001) {
                continue;
            }
            $lineItems[] = $this->lineItemFromProduct($product, $price);
            $total += $price;
        }

        if ($lineItems === []) {
            return null;
        }

        $score = count($lineItems) / max($total, 0.01);

        return $this->bundlePayload($store, $lineItems, $total, $score);
    }

    /**
     * @param  Collection<int, SmProduct>  $products
     * @return array{store: SmStore, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}|null
     */
    private function buildFastestBundle(SmStore $store, Collection $products, float $budgetTotal): ?array
    {
        $targetMin = $budgetTotal * 0.45;
        $sorted = $products->sortByDesc(fn (SmProduct $p) => $this->effectivePrice($p))->values();
        $lineItems = [];
        $total = 0.0;

        foreach ($sorted as $product) {
            $price = $this->effectivePrice($product);
            if ($price <= 0) {
                continue;
            }
            if ($total + $price > $budgetTotal + 0.0001) {
                continue;
            }
            $lineItems[] = $this->lineItemFromProduct($product, $price);
            $total += $price;
            if ($total >= $targetMin) {
                break;
            }
        }

        if ($lineItems === [] || $total < $targetMin * 0.25) {
            return null;
        }

        $itemCount = count($lineItems);
        $utilization = $total / max($budgetTotal, 0.01);
        $score = 500 - ($itemCount * 25) + ($utilization * 100);

        return $this->bundlePayload($store, $lineItems, $total, $score);
    }

    /**
     * @param  Collection<int, SmProduct>  $products
     * @return array{store: SmStore, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}|null
     */
    private function buildBalancedBundle(SmStore $store, Collection $products, float $budgetTotal): ?array
    {
        $byCategory = $products->groupBy(fn (SmProduct $p) => $p->category_id ?? 0);
        $roundRobin = collect();
        $keys = $byCategory->keys()->sort()->values();
        $maxRounds = 50;
        for ($r = 0; $r < $maxRounds; $r++) {
            foreach ($keys as $key) {
                $group = $byCategory->get($key);
                if (! $group instanceof Collection) {
                    continue;
                }
                $picked = $group->sortBy(fn (SmProduct $p) => $this->effectivePrice($p))->first();
                if ($picked instanceof SmProduct) {
                    $roundRobin->push($picked);
                    $byCategory->put($key, $group->reject(fn (SmProduct $p) => $p->id === $picked->id)->values());
                }
            }
        }

        $lineItems = [];
        $total = 0.0;
        foreach ($roundRobin->unique('id')->values() as $product) {
            $price = $this->effectivePrice($product);
            if ($price <= 0) {
                continue;
            }
            if ($total + $price > $budgetTotal + 0.0001) {
                break;
            }
            $lineItems[] = $this->lineItemFromProduct($product, $price);
            $total += $price;
        }

        if ($lineItems === []) {
            return null;
        }

        $distinctCategories = collect($lineItems)->map(function (array $li) use ($products): ?int {
            $p = $products->firstWhere('id', $li['productId']);

            return $p?->category_id;
        })->filter()->unique()->count();

        $score = (float) $distinctCategories * 10 + count($lineItems);

        return $this->bundlePayload($store, $lineItems, $total, $score);
    }

    /**
     * @param  list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>  $lineItems
     * @return array{store: SmStore, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}
     */
    private function bundlePayload(SmStore $store, array $lineItems, float $total, float $score): array
    {
        $qty = array_sum(array_column($lineItems, 'quantity'));
        $parts = [];
        foreach ($lineItems as $li) {
            $parts[] = $li['quantity'].'× '.$li['name'];
        }
        $estimatedMinutes = min(120, 12 + (3 * count($lineItems)));

        return [
            'store' => $store,
            'lineItems' => $lineItems,
            'totalPrice' => round($total, 2),
            'totalProducts' => $qty,
            'itemsDescription' => implode('، ', $parts),
            'estimatedMinutes' => $estimatedMinutes,
            'score' => $score,
        ];
    }

    /**
     * @param  array{store: SmStore, lineItems: list<array{productId: int, name: string, quantity: int, unitPrice: float, lineTotal: float, imageUrl: string|null}>, totalPrice: float, totalProducts: int, itemsDescription: string, estimatedMinutes: int, score: float}  $bundle
     * @return array<string, mixed>
     */
    private function formatBundle(string $label, string $labelAr, array $bundle): array
    {
        $store = $bundle['store'];

        return [
            'label' => $label,
            'labelAr' => $labelAr,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'logo' => $store->logo,
                'cover' => $store->cover,
            ],
            'totalProducts' => $bundle['totalProducts'],
            'itemsDescription' => $bundle['itemsDescription'],
            'totalPrice' => $bundle['totalPrice'],
            'estimatedMinutes' => $bundle['estimatedMinutes'],
            'lineItems' => $bundle['lineItems'],
        ];
    }
}
