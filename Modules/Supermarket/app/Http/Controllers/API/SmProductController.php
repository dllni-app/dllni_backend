<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Modules\Supermarket\Data\SmProductData;
use Modules\Supermarket\Http\Requests\SmProductImportRequest;
use Modules\Supermarket\Http\Requests\SmProductRequest;
use Modules\Supermarket\Http\Requests\SmProductRequests\SmProductFilterRequest;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Services\SmProductService;

final class SmProductController
{
    public function __construct(
        private SmProductService $service,
        private ActivityLogService $activityLogService
    ) {}

    public function index(SmProductFilterRequest $request): AnonymousResourceCollection
    {
        $products = SmProduct::getQuery()
            ->with('store', 'category', 'media', 'offerProducts.offer')
            ->paginate($request->get('perPage', 20));

        return SmProductResource::collection($products);
    }

    public function availableCount(): JsonResponse
    {
        $availableProductsCount = SmProduct::query()
            ->where('is_available', true)
            ->count();

        return response()->json([
            'count' => $availableProductsCount,
        ]);
    }

    public function store(SmProductRequest $request): SmProductResource
    {
        $product = $this->service->store(
            SmProductData::from($request->validated()),
            $this->extractImages($request)
        );

        return SmProductResource::make($product->load('store', 'category', 'media', 'offerProducts.offer'));
    }

    public function import(SmProductImportRequest $request): JsonResponse
    {
        $result = $this->service->importFromSpreadsheet(
            $request->file('file'),
            (int) $request->integer('storeId'),
            (int) $request->integer('categoryId')
        );

        return response()->json($result, Response::HTTP_CREATED);
    }

    public function show(SmProduct $smProduct): SmProductResource
    {
        return SmProductResource::make($smProduct->load('store', 'category', 'media', 'offerProducts.offer'));
    }

    public function update(SmProductRequest $request, SmProduct $smProduct): SmProductResource
    {
        $product = $this->service->update(
            SmProductData::from($request->validated()),
            $smProduct,
            $this->extractImages($request)
        );

        return SmProductResource::make($product->load('store', 'category', 'media', 'offerProducts.offer'));
    }

    public function destroy(SmProduct $smProduct): Response
    {
        $productName = $smProduct->name;
        $storeId = (int) $smProduct->store_id;
        $smProduct->delete();
        $this->activityLogService->logSmProductDeleted($productName, $storeId);

        return response()->noContent();
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function extractImages(SmProductRequest $request): array
    {
        $primaryImage = $request->file('image');
        $galleryImages = $request->file('images', []);

        if ($galleryImages instanceof UploadedFile) {
            $galleryImages = [$galleryImages];
        }

        if ($primaryImage instanceof UploadedFile) {
            array_unshift($galleryImages, $primaryImage);
        }

        return array_values(array_filter(
            $galleryImages,
            static fn (mixed $file): bool => $file instanceof UploadedFile
        ));
    }
}
