<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Data\SmProductData;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Models\SmProduct;
use Rap2hpoutre\FastExcel\FastExcel;
use Throwable;

final class SmProductService
{
    public function store(SmProductData $data, ?UploadedFile $image = null): SmProduct
    {
        return DB::transaction(static function () use ($data, $image) {
            $product = SmProduct::create($data->onlyModelAttributes());

            if ($image !== null) {
                $product->addMedia($image)->toMediaCollection(SmProduct::IMAGE_COLLECTION);
            }

            return $product;
        });
    }

    public function update(SmProductData $data, SmProduct $product, ?UploadedFile $image = null): SmProduct
    {
        return DB::transaction(static function () use ($data, $product, $image) {
            tap($product)->update($data->onlyModelAttributes());

            if ($image !== null) {
                $product->addMedia($image)->toMediaCollection(SmProduct::IMAGE_COLLECTION);
            }

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
