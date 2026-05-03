<?php

declare(strict_types=1);

use App\Services\RestaurantSystemAlertGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Supermarket\Jobs\DispatchDueSmartListSchedulesJob;
use Modules\Supermarket\Services\OpenFoodFactsMasterProductImportService;
use Modules\User\Jobs\ProcessExpiredRestaurantGroupOrdersJob;

Artisan::command('restaurant:generate-system-alerts', function (RestaurantSystemAlertGenerator $generator): int {
    $count = $generator->handle();

    $this->info("Restaurant alerts generated: {$count}");

    return 0;
})->purpose('Generate proactive system alerts for restaurant operations');

Schedule::command('restaurant:generate-system-alerts')->everyFiveMinutes();

Artisan::command('supermarket:process-smart-list-schedules', function (): int {
    DispatchDueSmartListSchedulesJob::dispatch();

    $this->info('Smart list schedules processing job dispatched.');

    return 0;
})->purpose('Dispatch processing for due smart list schedules');

Schedule::command('supermarket:process-smart-list-schedules')->everyFiveMinutes();

Artisan::command('restaurants:process-group-orders', function (): int {
    ProcessExpiredRestaurantGroupOrdersJob::dispatch();

    $this->info('Expired restaurant group orders processing job dispatched.');

    return 0;
})->purpose('Dispatch processing for expired restaurant group orders');

Schedule::command('restaurants:process-group-orders')->everyMinute();

Artisan::command(
    'supermarket:import-openfoodfacts-master-products {source?} {--country=en:syria} {--limit=} {--chunk=500} {--skip-images} {--dry-run}',
    function (OpenFoodFactsMasterProductImportService $service): int {
        $source = $this->argument('source');
        $country = mb_trim((string) ($this->option('country') ?? 'en:syria'));
        $country = $country !== '' ? $country : 'en:syria';

        $chunkOption = $this->option('chunk');
        $chunk = is_numeric($chunkOption) ? (int) $chunkOption : 500;
        if ($chunk < 1) {
            $this->error('The --chunk option must be greater than or equal to 1.');

            return 1;
        }

        $limitOption = $this->option('limit');
        $limit = null;
        if ($limitOption !== null && $limitOption !== '') {
            if (! is_numeric($limitOption) || (int) $limitOption < 1) {
                $this->error('The --limit option must be a positive integer.');

                return 1;
            }

            $limit = (int) $limitOption;
        }

        $skipImages = (bool) $this->option('skip-images');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $stats = $service->import(
                source: is_string($source) ? $source : null,
                countryTag: $country,
                limit: $limit,
                chunkSize: $chunk,
                skipImages: $skipImages,
                dryRun: $dryRun
            );
        } catch (Throwable $exception) {
            $this->error('OpenFoodFacts import failed: '.$exception->getMessage());

            return 1;
        }

        $this->newLine();
        $this->line('OpenFoodFacts import summary:');
        $this->line('  scanned rows: '.$stats['scanned_rows']);
        $this->line('  matched country rows: '.$stats['matched_country_rows']);
        $this->line('  created: '.$stats['created']);
        $this->line('  updated: '.$stats['updated']);
        $this->line('  skipped missing barcode/name: '.$stats['skipped_missing_barcode_or_name']);
        $this->line('  skipped unchanged hash: '.$stats['skipped_unchanged_hash']);
        $this->line('  image imported: '.$stats['image_imported']);
        $this->line('  image failed: '.$stats['image_failed']);
        $this->line('  JSON parse errors: '.$stats['json_parse_errors']);
        $this->newLine();

        $this->info('OpenFoodFacts master product import completed.');

        return 0;
    }
)->purpose('Import Syrian OpenFoodFacts products into the master catalog');
