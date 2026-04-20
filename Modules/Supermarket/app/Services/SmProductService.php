<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Carbon\CarbonImmutable;
use App\Models\MasterProduct;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Data\SmProductData;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Models\SmCategory;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Rap2hpoutre\FastExcel\FastExcel;
use Throwable;

final class SmProductService
{
    public function __construct(private ActivityLogService $activityLogService) {}

    /**
     * @param  array<int, int>  $masterProductIds
     * @return Collection<int, SmProduct>
     */
    public function bulkCreateFromMasterProductIdsForOwner(array $masterProductIds, User $owner): Collection
    {
        $store = SmStore::query()
            ->where('owner_user_id', $owner->id)
            ->orderBy('id')
            ->first();

        if (! $store) {
            throw ValidationException::withMessages([
                'store' => ['No store found for the authenticated store owner.'],
            ]);
        }

        $category = SmCategory::query()
            ->where('store_id', $store->id)
            ->orderBy('id')
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'category' => ['Cannot create products because the selected store has no categories.'],
            ]);
        }

        $requestedMasterProductIds = collect($masterProductIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $activeMasterProducts = MasterProduct::query()
            ->where('is_active', true)
            ->whereIn('id', $requestedMasterProductIds)
            ->get()
            ->keyBy('id');

        $missingOrInactiveMasterProductIds = $requestedMasterProductIds
            ->reject(fn (int $id): bool => $activeMasterProducts->has($id))
            ->values();

        if ($missingOrInactiveMasterProductIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'masterProductIds' => ['One or more requested master products are missing or inactive.'],
            ]);
        }

        $defaultExpiresAt = CarbonImmutable::now()->addYear()->toDateTimeString();

        return DB::transaction(function () use ($activeMasterProducts, $requestedMasterProductIds, $store, $category, $defaultExpiresAt): Collection {
            $products = collect();

            foreach ($requestedMasterProductIds as $masterProductId) {
                $masterProduct = $activeMasterProducts->get($masterProductId);

                $product = SmProduct::query()->create([
                    'store_id' => $store->id,
                    'category_id' => $category->id,
                    'master_product_id' => $masterProduct->id,
                    'name' => $masterProduct->name,
                    'barcode' => $masterProduct->barcode ?? '',
                    'source_type' => SmProductSource::CatalogSearch->value,
                    'description' => $masterProduct->description ?? '',
                    'price' => 0,
                    'discounted_price' => 0,
                    'stock_quantity' => 0,
                    'low_stock_threshold' => 0,
                    'expires_at' => $defaultExpiresAt,
                    'is_available' => true,
                ]);

                $this->activityLogService->logSmProductCreated($product, (int) $store->id);
                $products->push($product);
            }

            return $products->load('store', 'category', 'media', 'offerProducts.offer');
        });
    }

    public function store(SmProductData $data, array $images = []): SmProduct
    {
        return DB::transaction(function () use ($data, $images) {
            $product = SmProduct::create($data->onlyModelAttributes());

            foreach ($images as $image) {
                $product->addMedia($image)->toMediaCollection(SmProduct::IMAGE_COLLECTION);
            }

            $this->activityLogService->logSmProductCreated($product, (int) $product->store_id);

            return $product;
        });
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function update(SmProductData $data, SmProduct $product, array $images = []): SmProduct
    {
        return DB::transaction(function () use ($data, $product, $images) {
            $oldAttributes = $product->getAttributes();
            tap($product)->update($data->onlyModelAttributes());

            if ($images !== []) {
                $product->clearMediaCollection(SmProduct::IMAGE_COLLECTION);

                foreach ($images as $image) {
                    $product->addMedia($image)->toMediaCollection(SmProduct::IMAGE_COLLECTION);
                }
            }

            $this->activityLogService->logSmProductUpdated($product, (int) $product->store_id, $oldAttributes);

            return $product;
        });
    }

    public function importFromSpreadsheet(UploadedFile $file, int $storeId, int $categoryId): array
    {
        $importPath = $this->buildImportPath($file);

        try {
            $rawRows = (new FastExcel())->import($importPath);
        } finally {
            if (is_file($importPath)) {
                @unlink($importPath);
            }
        }

        if ($rawRows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file is empty.'],
            ]);
        }

        $rows = $rawRows->map(fn (array $row): array => $this->normalizeRow($row));
        $missingColumns = $this->missingRequiredColumns($rows->first());

        if ($missingColumns !== []) {
            throw ValidationException::withMessages([
                'file' => ['Missing required column(s): '.implode(', ', $missingColumns).'.'],
            ]);
        }

        return DB::transaction(function () use ($rows, $storeId, $categoryId): array {
            $importedCount = 0;
            $failedRows = [];

            foreach ($rows as $index => $row) {
                $name = mb_trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    $failedRows[] = $index + 2;

                    continue;
                }

                $product = SmProduct::query()->create([
                    'store_id' => $storeId,
                    'category_id' => $categoryId,
                    'name' => $name,
                    'description' => mb_trim((string) ($row['description'] ?? '')) ?: null,
                    'source_type' => SmProductSource::BulkImport->value,
                    'price' => 0,
                    'stock_quantity' => 0,
                    'low_stock_threshold' => 0,
                    'is_available' => true,
                ]);

                $imageUrl = mb_trim((string) ($row['image'] ?? ''));
                if ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    try {
                        $product->addMediaFromUrl($imageUrl)->toMediaCollection(SmProduct::IMAGE_COLLECTION);
                    } catch (Throwable) {
                        // Keep product import resilient if a remote image URL is invalid/unreachable.
                    }
                }

                $importedCount++;
            }

            return [
                'totalRows' => $rows->count(),
                'importedCount' => $importedCount,
                'failedRows' => $failedRows,
            ];
        });
    }

    private function normalizeRow(array $row): array
    {
        $normalizedRow = [];

        foreach ($row as $key => $value) {
            $normalizedKey = mb_strtolower(mb_trim((string) $key));
            $normalizedKey = mb_ltrim($normalizedKey, "\xEF\xBB\xBF");
            $normalizedRow[$normalizedKey] = $value;
        }

        return $normalizedRow;
    }

    private function missingRequiredColumns(?array $firstRow): array
    {
        if ($firstRow === null) {
            return ['name', 'description', 'image'];
        }

        $requiredColumns = ['name', 'description', 'image'];
        $headers = array_keys($firstRow);

        return array_values(array_diff($requiredColumns, $headers));
    }

    private function buildImportPath(UploadedFile $file): string
    {
        $extension = mb_strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'csv');
        $tempPath = tempnam(sys_get_temp_dir(), 'sm_product_import_');

        if ($tempPath === false) {
            throw ValidationException::withMessages([
                'file' => ['Unable to create a temporary file for import.'],
            ]);
        }

        $importPath = $tempPath.'.'.$extension;

        if (! copy($file->getRealPath(), $importPath)) {
            @unlink($tempPath);

            throw ValidationException::withMessages([
                'file' => ['Unable to prepare uploaded file for import.'],
            ]);
        }

        @unlink($tempPath);

        return $importPath;
    }
}
