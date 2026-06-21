<?php

declare(strict_types=1);

use App\Services\RestaurantSystemAlertGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningNeighborhoodResolver;
use Modules\Delivery\Jobs\RecoverDriverTrustScoreJob;
use Modules\Supermarket\Jobs\DispatchDueSmartListSchedulesJob;
use Modules\Supermarket\Services\OpenFoodFactsMasterProductImportService;
use Modules\User\Models\UserAddress;
use Modules\User\Jobs\ProcessExpiredRestaurantGroupOrdersJob;
use App\Models\WorkerZone;

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

Artisan::command('delivery:recover-driver-trust', function (): int {
    RecoverDriverTrustScoreJob::dispatch();

    $this->info('Delivery driver trust recovery job dispatched.');

    return 0;
})->purpose('Recover delivery driver trust scores for eligible drivers');

Schedule::command('delivery:recover-driver-trust')->daily();

Artisan::command('cleaning:map-legacy-zones-to-neighborhoods {--dry-run}', function (CleaningNeighborhoodResolver $resolver): int {
    $dryRun = (bool) $this->option('dry-run');
    $summary = [
        'worker_zones_mapped' => 0,
        'worker_zones_unmatched' => 0,
        'user_addresses_mapped' => 0,
        'user_addresses_unmatched' => 0,
        'cleaning_bookings_mapped' => 0,
        'cleaning_bookings_unmatched' => 0,
    ];
    $unmatched = [
        'worker_zones' => [],
        'user_addresses' => [],
        'cleaning_bookings' => [],
    ];

    WorkerZone::query()
        ->whereNull('neighborhood_id')
        ->orderBy('id')
        ->chunkById(100, function ($zones) use ($resolver, $dryRun, &$summary, &$unmatched): void {
            foreach ($zones as $zone) {
                $name = is_string($zone->name) ? mb_trim($zone->name) : '';
                $neighborhood = $name !== '' ? $resolver->resolve(null, $name, activeOnly: false) : null;

                if ($neighborhood === null) {
                    $summary['worker_zones_unmatched']++;
                    $unmatched['worker_zones'][] = ['id' => $zone->id, 'name' => $zone->name];

                    continue;
                }

                $summary['worker_zones_mapped']++;

                if (! $dryRun) {
                    $zone->forceFill([
                        'neighborhood_id' => $neighborhood->id,
                        'name' => $neighborhood->name_ar,
                    ])->save();
                }
            }
        });

    UserAddress::query()
        ->whereNull('neighborhood_id')
        ->whereNotNull('neighborhood')
        ->orderBy('id')
        ->chunkById(100, function ($addresses) use ($resolver, $dryRun, &$summary, &$unmatched): void {
            foreach ($addresses as $address) {
                $name = is_string($address->neighborhood) ? mb_trim($address->neighborhood) : '';
                $neighborhood = $name !== '' ? $resolver->resolve(null, $name, activeOnly: false) : null;

                if ($neighborhood === null) {
                    $summary['user_addresses_unmatched']++;
                    $unmatched['user_addresses'][] = ['id' => $address->id, 'name' => $address->neighborhood];

                    continue;
                }

                $summary['user_addresses_mapped']++;

                if (! $dryRun) {
                    $address->forceFill([
                        'neighborhood_id' => $neighborhood->id,
                        'neighborhood' => $neighborhood->name_ar,
                    ])->save();
                }
            }
        });

    CleaningBooking::query()
        ->whereNull('neighborhood_id')
        ->orderBy('id')
        ->chunkById(100, function ($bookings) use ($resolver, $dryRun, &$summary, &$unmatched): void {
            foreach ($bookings as $booking) {
                $propertyDetails = is_array($booking->property_details) ? $booking->property_details : [];
                $candidates = array_filter([
                    is_string($booking->neighborhood_name) ? mb_trim($booking->neighborhood_name) : null,
                    is_string($propertyDetails['address'] ?? null) ? mb_trim((string) $propertyDetails['address']) : null,
                    is_string($propertyDetails['full_address'] ?? null) ? mb_trim((string) $propertyDetails['full_address']) : null,
                ]);

                $neighborhood = null;
                foreach ($candidates as $candidate) {
                    $neighborhood = $resolver->resolve(null, $candidate, activeOnly: false);

                    if ($neighborhood !== null) {
                        break;
                    }
                }

                if ($neighborhood === null) {
                    $summary['cleaning_bookings_unmatched']++;
                    $unmatched['cleaning_bookings'][] = [
                        'id' => $booking->id,
                        'reference' => $booking->neighborhood_name ?: ($propertyDetails['address'] ?? null),
                    ];

                    continue;
                }

                $summary['cleaning_bookings_mapped']++;

                if (! $dryRun) {
                    $booking->forceFill([
                        'neighborhood_id' => $neighborhood->id,
                        'neighborhood_name' => $neighborhood->name_ar,
                    ])->save();
                }
            }
        });

    $this->info($dryRun ? 'Legacy neighborhood mapping dry run completed.' : 'Legacy neighborhood mapping completed.');
    foreach ($summary as $key => $value) {
        $this->line(str_replace('_', ' ', $key).": {$value}");
    }

    foreach ($unmatched as $bucket => $rows) {
        if ($rows === []) {
            continue;
        }

        $this->newLine();
        $this->warn(str_replace('_', ' ', $bucket).' unmatched preview:');

        foreach (array_slice($rows, 0, 20) as $row) {
            $this->line('- '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if (count($rows) > 20) {
            $this->line('...and '.(count($rows) - 20).' more');
        }
    }

    return 0;
})->purpose('Map legacy worker zones, addresses, and bookings to seeded cleaning neighborhoods');

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
