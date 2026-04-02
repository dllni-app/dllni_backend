<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Resturants\Http\Resources\ProductResource;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Product;

final class UserProductDetailsController
{
    public function __invoke(Request $request, int $product): JsonResponse
    {
        $model = Product::query()
            ->where('is_available', true)
            ->with([
                'media',
                'restaurant.media',
                'category',
                'modifierGroups.modifiers',
                'substitutions',
            ])
            ->findOrFail($product);

        $user = $request->user('sanctum');
        if ($user !== null) {
            $isFavorited = Favorite::query()
                ->where('user_id', $user->id)
                ->where('favorable_type', Product::class)
                ->where('favorable_id', $model->id)
                ->exists();

            $model->setAttribute('isFavoritedByUser', $isFavorited);
        } else {
            $model->setAttribute('isFavoritedByUser', false);
        }

        return response()->json([
            'product' => ProductResource::make($model),
            'modifierGroups' => $model->modifierGroups
                ->sortBy('id')
                ->values()
                ->map(fn ($group) => [
                    'id' => $group->id,
                    'restaurantId' => $group->restaurant_id,
                    'name' => $group->name,
                    'isRequired' => $group->is_required,
                    'minSelections' => $group->min_selections,
                    'maxSelections' => $group->max_selections,
                    'modifiers' => $group->modifiers
                        ->sortBy('sort_order')
                        ->values()
                        ->map(fn ($modifier) => [
                            'id' => $modifier->id,
                            'modifierGroupId' => $modifier->modifier_group_id,
                            'name' => $modifier->name,
                            'price' => $modifier->price ? (float) $modifier->price : 0.0,
                            'sortOrder' => $modifier->sort_order,
                        ])
                        ->all(),
                ])
                ->all(),
        ]);
    }
}
