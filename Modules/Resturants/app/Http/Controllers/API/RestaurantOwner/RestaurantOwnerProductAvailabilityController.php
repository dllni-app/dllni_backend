<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Http\Requests\RestaurantOwner\OwnerProductAvailabilityRequest;
use Modules\Resturants\Http\Resources\ProductResource;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantOwnerProductAvailabilityController
{
    public function __invoke(
        OwnerProductAvailabilityRequest $request,
        Product $product,
        RestaurantOwnerContext $context
    ): JsonResponse {
        $context->ensureOwnedProduct($product);

        $mode = $request->validated('mode');
        $note = $request->validated('note');

        if ($mode === 'available') {
            $product->update([
                'is_available' => true,
                'unavailable_until' => null,
                'availability_note' => $note,
            ]);
        } elseif ($mode === 'sold_out_today') {
            $product->update([
                'is_available' => false,
                'unavailable_until' => now()->endOfDay(),
                'availability_note' => $note,
            ]);
        } else {
            $product->update([
                'is_available' => false,
                'unavailable_until' => null,
                'availability_note' => $note,
            ]);
        }

        return response()->json([
            'data' => ProductResource::make($product->fresh())->resolve(),
        ]);
    }
}
