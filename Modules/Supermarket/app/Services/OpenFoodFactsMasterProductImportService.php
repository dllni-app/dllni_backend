<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use App\Models\MasterProduct;
use App\Models\MasterProductAlias;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;
use SplFileObject;
use Throwable;

final class OpenFoodFactsMasterProductImportService
{
    public function __construct(private OpenFoodFactsMasterProductMapper $mapper) {}

    /**
     * @return array{
     *   scanned_rows:int,
     *   matched_country_rows:int,
     *   created:int,
     *   updated:int,
     *   skipped_missing_barcode_or_name:int,
     *   skipped_unchanged_hash:int,
     *   image_imported:int,
     *   image_failed:int,
     *   json_parse_errors:int
     * }
     */
    public function import(
        ?string $source,
        string $countryTag = 'en:syria',
        ?int $limit = null,
        int $chunkSize = 500,
        bool $skipImages = false,
        bool $dryRun = false
    ): array {
        if ($chunkSize < 1) {
            throw new RuntimeException('Chunk size must be greater than or equal to 1.');
        }

        if ($limit !== null && $limit < 1) {
            throw new RuntimeException('Limit must be greater than or equal to 1.');
        }

        $stats = $this->freshStats();
        $sourceDetails = $this->resolveSourcePath($source);
        $buffer = [];
        $processedMappedRows = 0;

        try {
            foreach ($this->iterateJsonlLines($sourceDetails['path']) as $line) {
                $line = mb_trim($line);
                if ($line === '') {
                    continue;
                }

                $stats['scanned_rows']++;

                try {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $stats['json_parse_errors']++;

                    continue;
                }

                if (! is_array($record)) {
                    $stats['json_parse_errors']++;

                    continue;
                }

                if (! $this->mapper->countryMatches($record['countries_tags'] ?? null, $countryTag)) {
                    continue;
                }

                $stats['matched_country_rows']++;

                $mapped = $this->mapper->map($record);
                if ($mapped === null) {
                    $stats['skipped_missing_barcode_or_name']++;

                    continue;
                }

                if ($limit !== null && $processedMappedRows >= $limit) {
                    break;
                }

                $buffer[] = $mapped;
                $processedMappedRows++;

                if (count($buffer) >= $chunkSize) {
                    $this->importChunk($buffer, $stats, $skipImages, $dryRun);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->importChunk($buffer, $stats, $skipImages, $dryRun);
            }
        } finally {
            if ($sourceDetails['delete_after'] && is_file($sourceDetails['path'])) {
                @unlink($sourceDetails['path']);
            }
        }

        return $stats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $stats
     */
    private function importChunk(array $rows, array &$stats, bool $skipImages, bool $dryRun): void
    {
        $barcodes = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['barcode'],
            $rows
        )));

        $existingByBarcode = MasterProduct::query()
            ->whereIn('barcode', $barcodes)
            ->get()
            ->keyBy('barcode');

        foreach ($rows as $mapped) {
            $barcode = (string) $mapped['barcode'];
            /** @var MasterProduct|null $existing */
            $existing = $existingByBarcode->get($barcode);

            if (
                $existing !== null
                && $existing->openfoodfacts_payload_hash !== null
                && $existing->openfoodfacts_payload_hash === $mapped['openfoodfacts_payload_hash']
            ) {
                $stats['skipped_unchanged_hash']++;

                continue;
            }

            if ($dryRun) {
                if ($existing === null) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }

                continue;
            }

            $payload = [
                'name' => $mapped['name'],
                'barcode' => $barcode,
                'brand' => $mapped['brand'],
                'unit' => $mapped['unit'],
                'description' => $mapped['description'],
                'is_active' => true,
                'openfoodfacts_url' => $mapped['openfoodfacts_url'],
                'openfoodfacts_last_modified_at' => $mapped['openfoodfacts_last_modified_at'],
                'openfoodfacts_imported_at' => now(),
                'openfoodfacts_payload_hash' => $mapped['openfoodfacts_payload_hash'],
                'openfoodfacts_countries_tags' => $mapped['openfoodfacts_countries_tags'],
            ];

            if ($existing === null) {
                $masterProduct = MasterProduct::query()->create($payload);
                $existingByBarcode->put($barcode, $masterProduct);
                $stats['created']++;
            } else {
                $existing->fill($payload);
                $existing->save();
                $masterProduct = $existing->fresh();
                $existingByBarcode->put($barcode, $masterProduct);
                $stats['updated']++;
            }

            if (! $masterProduct instanceof MasterProduct) {
                continue;
            }

            $this->syncAliases(
                masterProduct: $masterProduct,
                canonicalName: (string) $mapped['name'],
                aliases: is_array($mapped['aliases'] ?? null) ? $mapped['aliases'] : [],
            );

            if ($skipImages) {
                continue;
            }

            $imageUrl = is_string($mapped['image_url'] ?? null) ? $mapped['image_url'] : null;
            $imageImported = $this->importImage($masterProduct, $barcode, $imageUrl, (string) $mapped['openfoodfacts_url']);

            if ($imageImported) {
                $stats['image_imported']++;
            } else {
                $stats['image_failed']++;
            }

            $sleepMs = (int) config('services.openfoodfacts.image_sleep_ms', 100);
            if ($sleepMs > 0) {
                Sleep::for($sleepMs)->milliseconds();
            }
        }
    }

    /**
     * @param  array<int, mixed>  $aliases
     */
    private function syncAliases(MasterProduct $masterProduct, string $canonicalName, array $aliases): void
    {
        $canonicalLower = mb_strtolower(mb_trim($canonicalName));
        $seen = [$canonicalLower => true];

        foreach ($aliases as $rawAlias) {
            if (! is_scalar($rawAlias)) {
                continue;
            }

            $alias = mb_trim((string) $rawAlias);
            if ($alias === '') {
                continue;
            }

            $normalizedAlias = mb_strtolower($alias);
            if (isset($seen[$normalizedAlias])) {
                continue;
            }

            $seen[$normalizedAlias] = true;

            $exists = MasterProductAlias::query()
                ->where('master_product_id', $masterProduct->id)
                ->whereRaw('LOWER(alias) = ?', [$normalizedAlias])
                ->exists();

            if (! $exists) {
                MasterProductAlias::query()->create([
                    'master_product_id' => $masterProduct->id,
                    'alias' => $alias,
                ]);
            }
        }
    }

    private function importImage(MasterProduct $masterProduct, string $barcode, ?string $imageUrl, string $sourceUrl): bool
    {
        if ($imageUrl === null) {
            return false;
        }

        $maxBytes = (int) config('services.openfoodfacts.image_max_bytes', 5 * 1024 * 1024);

        try {
            $response = $this->httpClient()
                ->accept('*/*')
                ->get($imageUrl);

            if (! $response->successful()) {
                return false;
            }

            $contentType = mb_strtolower((string) ($response->header('Content-Type') ?? ''));
            $mimeType = mb_trim(explode(';', $contentType)[0]);
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];

            if (! in_array($mimeType, $allowedMimeTypes, true)) {
                return false;
            }

            $body = $response->body();
            $bytes = mb_strlen($body, '8bit');

            if ($bytes <= 0 || $bytes > $maxBytes) {
                return false;
            }

            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => null,
            };

            if ($extension === null) {
                return false;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'off-image-');
            if ($tempPath === false) {
                return false;
            }

            $imagePath = $tempPath.'.'.$extension;
            @unlink($tempPath);

            if (file_put_contents($imagePath, $body) === false) {
                @unlink($imagePath);

                return false;
            }

            try {
                $newMedia = $masterProduct->addMedia($imagePath)
                    ->usingFileName('off-'.$barcode.'.'.$extension)
                    ->withCustomProperties([
                        'source' => 'openfoodfacts',
                        'barcode' => $barcode,
                        'source_url' => $sourceUrl,
                        'image_url' => $imageUrl,
                        'imported_at' => CarbonImmutable::now()->toIso8601String(),
                        'attribution' => 'Image sourced from OpenFoodFacts contributors (ODbL).',
                    ])
                    ->toMediaCollection(MasterProduct::IMAGE_COLLECTION);

                $masterProduct->getMedia(MasterProduct::IMAGE_COLLECTION)
                    ->filter(fn ($media): bool => $media->id !== $newMedia->id)
                    ->each(fn ($media): bool => (bool) $media->delete());
            } finally {
                if (is_file($imagePath)) {
                    @unlink($imagePath);
                }
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('OpenFoodFacts image import failed: exception during media save.', [
                'barcode' => $barcode,
                'image_url' => $imageUrl,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return \Generator<int, string>
     */
    private function iterateJsonlLines(string $path): \Generator
    {
        $isGz = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'gz';

        if ($isGz) {
            $handle = gzopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open gzipped source file: '.$path);
            }

            try {
                while (! gzeof($handle)) {
                    $line = gzgets($handle);
                    if ($line === false) {
                        continue;
                    }

                    yield $line;
                }
            } finally {
                gzclose($handle);
            }

            return;
        }

        $file = new SplFileObject($path, 'r');
        while (! $file->eof()) {
            $line = $file->fgets();
            if ($line === false) {
                continue;
            }

            yield $line;
        }
    }

    /**
     * @return array{path:string,delete_after:bool}
     */
    private function resolveSourcePath(?string $source): array
    {
        $configuredSource = (string) config('services.openfoodfacts.products_jsonl_url');
        $resolvedSource = mb_trim((string) ($source ?? $configuredSource));

        if ($resolvedSource === '') {
            throw new RuntimeException('OpenFoodFacts source is empty. Provide a source or set OPENFOODFACTS_PRODUCTS_JSONL_URL.');
        }

        if (filter_var($resolvedSource, FILTER_VALIDATE_URL)) {
            $downloadPath = $this->downloadSource($resolvedSource);

            return [
                'path' => $downloadPath,
                'delete_after' => true,
            ];
        }

        if (! is_file($resolvedSource) || ! is_readable($resolvedSource)) {
            throw new RuntimeException('OpenFoodFacts source file is missing or not readable: '.$resolvedSource);
        }

        return [
            'path' => $resolvedSource,
            'delete_after' => false,
        ];
    }

    private function downloadSource(string $url): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'off-source-');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to create temporary file for OpenFoodFacts source download.');
        }

        $isGz = mb_strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)) === 'gz';
        $downloadPath = $tempPath.($isGz ? '.jsonl.gz' : '.jsonl');
        @unlink($tempPath);

        $response = $this->httpClient()
            ->sink($downloadPath)
            ->get($url);

        if (! $response->successful()) {
            @unlink($downloadPath);

            throw new RuntimeException('Failed to download OpenFoodFacts source. HTTP status: '.$response->status());
        }

        if (! is_file($downloadPath) || filesize($downloadPath) === 0) {
            @unlink($downloadPath);

            throw new RuntimeException('Downloaded OpenFoodFacts source is empty.');
        }

        return $downloadPath;
    }

    private function httpClient(): PendingRequest
    {
        $timeout = (int) config('services.openfoodfacts.timeout', 120);
        $retryTimes = (int) config('services.openfoodfacts.retry_times', 3);
        $retrySleep = (int) config('services.openfoodfacts.retry_sleep', 500);
        $userAgent = (string) config('services.openfoodfacts.user_agent', 'DllniBackend/1.0 (contact: backend@dllni.local)');

        return Http::timeout($timeout)
            ->withUserAgent($userAgent)
            ->retry($retryTimes, $retrySleep);
    }

    /**
     * @return array<string, int>
     */
    private function freshStats(): array
    {
        return [
            'scanned_rows' => 0,
            'matched_country_rows' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_missing_barcode_or_name' => 0,
            'skipped_unchanged_hash' => 0,
            'image_imported' => 0,
            'image_failed' => 0,
            'json_parse_errors' => 0,
        ];
    }
}
