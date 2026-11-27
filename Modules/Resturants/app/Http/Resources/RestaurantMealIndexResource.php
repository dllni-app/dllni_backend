<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Services\RestaurantMealIndexDocument;

/** Preserve the internal AI catalog's existing camel-case contract. */
final class RestaurantMealIndexResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fields = RestaurantMealIndexDocument::fields($this->resource);
        return array_replace((new ProductResource($this->resource))->toArray($request), [
            'searchTags' => $fields['search_tags'],
            'isAvailable' => $fields['is_available'],
            'isAvailableNow' => $fields['is_available'],
            'discountedPrice' => $fields['discounted_price'],
        ]);
    }
}
