<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Resturants\Models\Product;

/** Same-restaurant, independently orderable complements; never substitutes or modifiers. */
final class RestaurantMealComplements
{
    private const KINDS = ['side_dish', 'beverage', 'dessert', 'standalone_sauce'];

    public static function supports(Product $product): bool
    {
        return $product->isIndependentlyOrderable()
            && in_array($product->product_kind, ['main_dish', 'combo_meal'], true)
            && (int) $product->restaurant_id > 0;
    }

    /** Candidates in the merchant's own catalog, including temporarily unavailable items. */
    public static function ownerCandidates(Product $product): Builder
    {
        return Product::query()->independentlyOrderable()
            ->where('restaurant_id', $product->restaurant_id)
            ->whereKeyNot($product->id)
            ->whereIn('product_kind', self::KINDS)
            ->whereNotNull('price')->where('price', '>=', 0);
    }

    /** @return list<array<string, mixed>> */
    public function forProduct(Product $product): array
    {
        if (! self::supports($product)) {
            return [];
        }

        $eligible = self::ownerCandidates($product)
            ->where(fn (Builder $q) => $q->where('is_available', true)
                ->orWhere(fn (Builder $expired) => $expired->where('is_available', false)
                    ->whereNotNull('unavailable_until')->where('unavailable_until', '<', now())))
            ->where(fn ($q) => $q->whereNull('stock_quantity')->orWhere('stock_quantity', '>', 0))
            ->whereHas('restaurant', fn ($q) => $q->where('is_active', true))
            ->with(['media' => fn ($media) => $media->where('collection_name', 'primary-image'), 'offers' => fn ($offers) => $offers->activeAt()])
            ->withCount('modifierGroups');

        $ids = $product->complementary_product_ids;
        if ($ids !== null) {
            // Explicit selections never silently become automatic suggestions.
            // Revalidate deleted, reclassified and unavailable candidates on every read.
            $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 6);
            $rows = $eligible->whereIn('products.id', $ids)->get()->keyBy('id');
            $result = [];
            foreach ($ids as $id) {
                if ($candidate = $rows->get($id)) {
                    $result[] = $this->serialize($candidate);
                }
            }

            return $result;
        }

        // Automatic discovery uses reviewed taxonomy; combos usually include sides and drinks.
        $eligible->where('classification_status', 'reviewed');
        $kinds = $product->product_kind === 'combo_meal'
            ? ['dessert']
            : ['side_dish', 'beverage', 'dessert'];

        $candidates = collect($kinds)->mapWithKeys(function (string $kind) use ($eligible): array {
            $query = (clone $eligible)->setEagerLoads([]);
            if ($kind === 'side_dish') {
                $query->whereIn('product_kind', ['side_dish', 'standalone_sauce'])
                    ->orderByRaw("CASE WHEN product_kind = 'standalone_sauce' OR item_type = 'side' THEN 0 WHEN item_type = 'appetizer' THEN 1 ELSE 2 END");
            } else {
                $query->where('product_kind', $kind);
            }

            return [$kind => $query->orderByDesc('is_featured')->orderBy('id')->limit(2)->get()];
        });

        // Fetch media and discounts once across all bounded candidate groups.
        (new EloquentCollection($candidates->flatten(1)->all()))->load([
            'media' => fn ($media) => $media->where('collection_name', 'primary-image'),
            'offers' => fn ($offers) => $offers->activeAt(),
        ]);

        $result = [];
        for ($round = 0; $round < 2; $round++) {
            foreach ($kinds as $kind) {
                $candidate = $candidates->get($kind)?->get($round);
                if ($candidate !== null) {
                    $result[] = $this->serialize($candidate);
                }
            }
        }

        return $result;
    }

    private function serialize(Product $candidate): array
    {
        return [
            'id' => (int) $candidate->id,
            'restaurantId' => (int) $candidate->restaurant_id,
            'name' => $candidate->name,
            'productKind' => $candidate->product_kind,
            'price' => number_format($candidate->effectivePrice(), 2, '.', ''),
            'currency' => config('app.currency', 'SYP'),
            'imageUrl' => $candidate->getFirstMediaUrl('primary-image') ?: null,
            'requiresConfiguration' => $candidate->modifier_groups_count > 0,
        ];
    }
}
