<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use App\Models\MasterProduct;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Data\SmProductData;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Http\Requests\StoreOwnerMasterProductCreateRequest;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmCategory;
use Modules\Supermarket\Services\SmProductService;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerMasterProductCreateController
{
    public function __invoke(
        StoreOwnerMasterProductCreateRequest $request,
        StoreOwnerContextService $context,
        SmProductService $service
    ): JsonResource {
        $validated = $request->validated();
        $context->owner();

        $store = $context->store((int) $validated['storeId']);

        $category = SmCategory::query()
            ->where('id', (int) $validated['categoryId'])
            ->where('store_id', $store->id)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'categoryId' => ['The selected category is invalid for this store.'],
            ]);
        }

        $masterProduct = MasterProduct::query()
            ->where('is_active', true)
            ->find((int) $validated['masterProductId']);

        if (! $masterProduct) {
            throw ValidationException::withMessages([
                'masterProductId' => ['The selected master product is invalid.'],
            ]);
        }

        $product = $service->store(SmProductData::from([
            'storeId' => $store->id,
            'categoryId' => $category->id,
            'masterProductId' => $masterProduct->id,
            'name' => $masterProduct->name,
            'barcode' => $masterProduct->barcode,
            'sourceType' => SmProductSource::CatalogSearch->value,
            'description' => $validated['description'] ?? $masterProduct->description,
            'price' => (float) $validated['price'],
            'discountedPrice' => isset($validated['discountedPrice']) ? (float) $validated['discountedPrice'] : null,
            'stockQuantity' => (int) $validated['stockQuantity'],
            'lowStockThreshold' => isset($validated['lowStockThreshold']) ? (int) $validated['lowStockThreshold'] : 0,
            'expiresAt' => $validated['expiresAt'] ?? null,
            'isAvailable' => (bool) ($validated['isAvailable'] ?? true),
        ]));

        return SmProductResource::make($product->load('store', 'category', 'media', 'offerProducts.offer'));
    }
}
