<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Supermarket\Data\SmProductData;
use Modules\Supermarket\Http\Requests\SmProductImportRequest;
use Modules\Supermarket\Http\Requests\SmProductRequest;
use Modules\Supermarket\Http\Requests\SmProductRequests\SmProductFilterRequest;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Services\SmProductService;
use Modules\Supermarket\Services\SmSemanticProductSearchService;

final class SmProductController
{
    public function __construct(
        private SmProductService $service,
        private ActivityLogService $activityLogService,
        private SmSemanticProductSearchService $semanticSearchService,
    ) {}

    public function index(SmProductFilterRequest $request): AnonymousResourceCollection
    {
        $query = $this->resolveSemanticQuery($request);

        if ($query !== null) {
            $semanticPaginator = $this->semanticSearch($request, $query);

            if ($semanticPaginator !== null) {
                return SmProductResource::collection($semanticPaginator);
            }
        }

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

    private function resolveSemanticQuery(SmProductFilterRequest $request): ?string
    {
        $query = $request->validated('query', $request->validated('search', $request->input('filter.search')));

        if (! is_string($query)) {
            return null;
        }

        $trimmed = mb_trim($query);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function semanticSearch(SmProductFilterRequest $request, string $query): ?LengthAwarePaginator
    {
        $perPage = $request->integer('perPage', 20);
        $page = max(1, $request->integer('page', 1));

        $payload = [
            'query' => $query,
            'top_k' => $request->integer('top_k', max($perPage * $page, $perPage)),
        ];

        $storeId = $request->input('store_id', $request->input('filter.storeId'));
        if (is_numeric($storeId)) {
            $payload['store_id'] = (string) $storeId;
        }

        $categoryId = $request->input('category_id', $request->input('filter.categoryId'));
        if (is_numeric($categoryId)) {
            $payload['category_id'] = (string) $categoryId;
        }

        $priceMin = $request->input('price_min');
        if (is_numeric($priceMin)) {
            $payload['price_min'] = (float) $priceMin;
        }

        $priceMax = $request->input('price_max');
        if (is_numeric($priceMax)) {
            $payload['price_max'] = (float) $priceMax;
        }

        $isAvailable = $request->input('is_available', $request->input('filter.isAvailable'));
        if ($isAvailable !== null) {
            $payload['is_available'] = filter_var($isAvailable, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $results = $this->semanticSearchService->search($payload);

        if ($results === null) {
            return null;
        }

        if ($results === []) {
            return $this->paginateCollection(collect(), $perPage, $page, $request->query());
        }

        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $results)));

        $products = SmProduct::query()
            ->whereIn('id', $ids)
            ->with('store', 'category', 'media', 'offerProducts.offer')
            ->get()
            ->keyBy('id');

        $ordered = collect($results)
            ->map(function (array $row) use ($products): ?SmProduct {
                $product = $products->get($row['id']);

                if (! $product instanceof SmProduct) {
                    return null;
                }

                $product->setAttribute('semantic_score', $row['score']);

                return $product;
            })
            ->filter(fn ($item): bool => $item instanceof SmProduct)
            ->values();

        return $this->paginateCollection($ordered, $perPage, $page, $request->query());
    }

    /**
     * @param  Collection<int, SmProduct>  $items
     * @param  array<string, mixed>  $query
     */
    private function paginateCollection(Collection $items, int $perPage, int $page, array $query): LengthAwarePaginator
    {
        $total = $items->count();
        $results = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => $query,
            ]
        );
    }
}
