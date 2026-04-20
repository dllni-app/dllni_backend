<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use App\Models\MasterProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
    ): JsonResponse {
        $validated = $request->validated();
        $context->owner();

        $store = $context->store((int) $validated['storeId']);
        $payloadProducts = $validated['products'];

        $categoryIds = collect($payloadProducts)
            ->pluck('categoryId')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $categories = SmCategory::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $categoryIds)
            ->get()
            ->keyBy('id');

        $missingCategoryIds = $categoryIds
            ->reject(fn (int $id): bool => $categories->has($id))
            ->values()
            ->all();

        if ($missingCategoryIds !== []) {
            throw ValidationException::withMessages([
                'products' => ['One or more categories are invalid for this store.'],
            ]);
        }

        $masterProductIds = collect($payloadProducts)
            ->pluck('masterProductId')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $masterProducts = MasterProduct::query()
            ->where('is_active', true)
            ->whereIn('id', $masterProductIds)
            ->get()
            ->keyBy('id');

        $missingMasterProductIds = $masterProductIds
            ->reject(fn (int $id): bool => $masterProducts->has($id))
            ->values()
            ->all();

        if ($missingMasterProductIds !== []) {
            throw ValidationException::withMessages([
                'products' => ['One or more master products are invalid.'],
            ]);
        }

        $createdProducts = collect($payloadProducts)
            ->map(function (array $payloadProduct) use ($categories, $masterProducts, $service, $store) {
                $category = $categories->get((int) $payloadProduct['categoryId']);
                $masterProduct = $masterProducts->get((int) $payloadProduct['masterProductId']);

                return $service->store(SmProductData::from([
                    'storeId' => $store->id,
                    'categoryId' => $category->id,
                    'masterProductId' => $masterProduct->id,
                    'name' => $payloadProduct['title'],
                    'barcode' => $masterProduct->barcode,
                    'sourceType' => SmProductSource::CatalogSearch->value,
                    'description' => $payloadProduct['description'] ?? $masterProduct->description,
                    'price' => (float) $payloadProduct['price'],
                    'discountedPrice' => isset($payloadProduct['discountedPrice']) ? (float) $payloadProduct['discountedPrice'] : null,
                    'stockQuantity' => (int) $payloadProduct['stockQuantity'],
                    'lowStockThreshold' => isset($payloadProduct['lowStockThreshold']) ? (int) $payloadProduct['lowStockThreshold'] : 0,
                    'expiresAt' => $payloadProduct['expiresAt'] ?? null,
                    'isAvailable' => (bool) ($payloadProduct['isAvailable'] ?? true),
                ]))->load('store', 'category', 'media', 'offerProducts.offer');
            });

        return SmProductResource::collection($createdProducts)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
