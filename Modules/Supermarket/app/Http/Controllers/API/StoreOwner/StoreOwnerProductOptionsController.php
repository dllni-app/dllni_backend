<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmModifier;
use Modules\Supermarket\Models\SmModifierGroup;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerProductOptionsController
{
    public function __invoke(
        Request $request,
        SmProduct $product,
        StoreOwnerContextService $context,
    ): JsonResponse {
        $store = $context->store((int) $product->store_id);

        $validated = $request->validate([
            'options' => ['present', 'array'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:120'],
            'options.*.isRequired' => ['required', 'boolean'],
            'options.*.minSelections' => ['required', 'integer', 'min:0'],
            'options.*.maxSelections' => ['required', 'integer', 'min:1'],
            'options.*.sortOrder' => ['nullable', 'integer', 'min:0'],
            'options.*.isActive' => ['required', 'boolean'],
            'options.*.modifiers' => ['present', 'array'],
            'options.*.modifiers.*.id' => ['nullable', 'integer'],
            'options.*.modifiers.*.name' => ['required', 'string', 'max:120'],
            'options.*.modifiers.*.price' => ['required', 'numeric', 'min:0'],
            'options.*.modifiers.*.sortOrder' => ['nullable', 'integer', 'min:0'],
            'options.*.modifiers.*.isAvailable' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($validated, $product, $store): void {
            $existingIds = $product->modifierGroups()
                ->pluck('sm_modifier_groups.id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $keptIds = [];

            foreach ($validated['options'] as $option) {
                $group = null;
                $requestedId = isset($option['id']) ? (int) $option['id'] : null;

                if ($requestedId !== null && in_array($requestedId, $existingIds, true)) {
                    $candidate = SmModifierGroup::query()
                        ->whereKey($requestedId)
                        ->where('store_id', $store->id)
                        ->firstOrFail();

                    if ($candidate->products()->count() > 1) {
                        $candidate->products()->detach($product->id);
                    } else {
                        $group = $candidate;
                    }
                }

                $group ??= new SmModifierGroup(['store_id' => $store->id]);
                $group->fill([
                    'store_id' => $store->id,
                    'name' => $option['name'],
                    'is_required' => (bool) $option['isRequired'],
                    'min_selections' => (int) $option['minSelections'],
                    'max_selections' => max(
                        (int) $option['maxSelections'],
                        (int) $option['minSelections'],
                        1,
                    ),
                    'sort_order' => (int) ($option['sortOrder'] ?? 0),
                    'is_active' => (bool) $option['isActive'],
                ])->save();

                $product->modifierGroups()->syncWithoutDetaching([$group->id]);
                $keptIds[] = (int) $group->id;

                $modifierIds = [];
                foreach ($option['modifiers'] as $modifierData) {
                    $modifier = null;
                    if (! empty($modifierData['id'])) {
                        $modifier = SmModifier::query()
                            ->whereKey((int) $modifierData['id'])
                            ->where('modifier_group_id', $group->id)
                            ->first();
                    }

                    $modifier ??= new SmModifier(['modifier_group_id' => $group->id]);
                    $modifier->fill([
                        'modifier_group_id' => $group->id,
                        'name' => $modifierData['name'],
                        'price' => (int) round((float) $modifierData['price']),
                        'sort_order' => (int) ($modifierData['sortOrder'] ?? 0),
                        'is_available' => (bool) $modifierData['isAvailable'],
                    ])->save();
                    $modifierIds[] = (int) $modifier->id;
                }

                $group->modifiers()
                    ->whereNotIn('id', $modifierIds ?: [-1])
                    ->delete();
            }

            $removeIds = array_values(array_diff($existingIds, $keptIds));
            if ($removeIds !== []) {
                $product->modifierGroups()->detach($removeIds);

                foreach ($removeIds as $removeId) {
                    $group = SmModifierGroup::query()->find($removeId);
                    if ($group !== null && ! $group->products()->exists()) {
                        $group->modifiers()->delete();
                        $group->delete();
                    }
                }
            }
        });

        $product->refresh()->load([
            'store',
            'category',
            'media',
            'offerProducts.offer',
            'masterProduct.media',
            'modifierGroups.modifiers',
        ]);

        return response()->json([
            'data' => SmProductResource::make($product),
            'message' => 'Product options updated successfully.',
        ]);
    }
}
