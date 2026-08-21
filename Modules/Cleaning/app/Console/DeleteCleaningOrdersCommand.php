<?php

declare(strict_types=1);

namespace Modules\Cleaning\Console;

use App\Models\Dispute;
use App\Models\SupportCase;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Models\CleaningBooking;

final class DeleteCleaningOrdersCommand extends Command
{
    protected $signature = 'cleaning:delete-orders
        {--dry-run : Preview matching orders and linked data without deleting anything}
        {--all : Delete all cleaning orders}
        {--ids=* : Cleaning order IDs; repeat the option or pass comma-separated IDs}
        {--codes=* : Cleaning order codes (booking numbers); repeat the option or pass comma-separated codes}
        {--except : Delete all cleaning orders except the orders selected by --ids and/or --codes}';

    protected $description = 'Delete cleaning orders and their linked data';

    /** @var array<string, array{table:string,column:string}> */
    private const DIRECT_LINKED_TABLES = [
        'bookingAddons' => ['table' => 'booking_addons', 'column' => 'cleaning_booking_id'],
        'rooms' => ['table' => 'cleaning_booking_rooms', 'column' => 'cleaning_booking_id'],
        'workerAssignments' => ['table' => 'cleaning_booking_worker_assignments', 'column' => 'cleaning_booking_id'],
        'workerRejections' => ['table' => 'cleaning_booking_worker_rejections', 'column' => 'cleaning_booking_id'],
        'priceAdjustmentRequests' => ['table' => 'cleaning_booking_price_adjustment_requests', 'column' => 'cleaning_booking_id'],
        'notificationDispatches' => ['table' => 'cleaning_notification_dispatches', 'column' => 'cleaning_booking_id'],
        'financialPenalties' => ['table' => 'cleaning_financial_penalties', 'column' => 'cleaning_booking_id'],
        'workerTrustLogs' => ['table' => 'worker_trust_logs', 'column' => 'cleaning_booking_id'],
    ];

    /** @var array<string, int> */
    private array $totals = [];

    public function handle(): int
    {
        $ids = $this->resolveIds();
        $codes = $this->resolveCodes();
        $deleteAll = (bool) $this->option('all');
        $except = (bool) $this->option('except');
        $dryRun = (bool) $this->option('dry-run');
        $hasSelectors = $ids !== [] || $codes !== [];

        if ($deleteAll && ($hasSelectors || $except)) {
            $this->error('Use --all by itself; do not combine it with --ids, --codes, or --except.');

            return self::INVALID;
        }

        if ($except && ! $hasSelectors) {
            $this->error('The --except flag requires at least one --ids or --codes value.');

            return self::INVALID;
        }

        if (! $deleteAll && ! $hasSelectors) {
            $this->error('Specify --all or at least one --ids/--codes value.');

            return self::INVALID;
        }

        if (! $this->validateSelectorsExist($ids, $codes, $except)) {
            return self::INVALID;
        }

        $query = $this->selectedOrdersQuery($deleteAll, $except, $ids, $codes);
        $matchedCount = (clone $query)->count();

        if ($matchedCount === 0) {
            $this->info('No matching cleaning orders found.');

            return self::SUCCESS;
        }

        $this->totals = $this->emptyTotals();
        $this->totals['orders'] = $matchedCount;

        if ($except) {
            $this->warn('EXCEPT MODE: selected IDs/codes will be preserved; every other cleaning order is targeted.');
        }

        if ($dryRun) {
            (clone $query)->chunkById(100, function ($bookings): void {
                foreach ($bookings as $booking) {
                    if ($booking instanceof CleaningBooking) {
                        $this->accumulateLinkedCounts($booking);
                    }
                }
            });

            $this->warn('DRY RUN: no data was deleted.');
            $this->renderSummary('Would delete');

            return self::SUCCESS;
        }

        $deletedOrders = 0;

        (clone $query)->chunkById(100, function ($bookings) use (&$deletedOrders): void {
            foreach ($bookings as $booking) {
                if (! $booking instanceof CleaningBooking) {
                    continue;
                }

                DB::transaction(function () use ($booking): void {
                    $lockedBooking = CleaningBooking::query()
                        ->whereKey($booking->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedBooking instanceof CleaningBooking) {
                        return;
                    }

                    $this->accumulateLinkedCounts($lockedBooking);
                    $this->deleteLinkedData($lockedBooking);
                    $lockedBooking->delete();
                });

                $deletedOrders++;
            }
        });

        $this->totals['orders'] = $deletedOrders;
        $this->info('Cleaning orders deleted successfully.');
        $this->renderSummary('Deleted');

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function resolveIds(): array
    {
        $rawValues = (array) $this->option('ids');
        $ids = [];

        foreach ($rawValues as $rawValue) {
            foreach (explode(',', (string) $rawValue) as $value) {
                $value = trim($value);

                if ($value === '' || ! ctype_digit($value) || (int) $value <= 0) {
                    if ($value !== '') {
                        $this->warn("Ignoring invalid cleaning order ID: {$value}");
                    }

                    continue;
                }

                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<int, string> */
    private function resolveCodes(): array
    {
        $rawValues = (array) $this->option('codes');
        $codes = [];

        foreach ($rawValues as $rawValue) {
            foreach (explode(',', (string) $rawValue) as $value) {
                $value = trim($value);

                if ($value !== '') {
                    $codes[] = $value;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $codes
     */
    private function validateSelectorsExist(array $ids, array $codes, bool $except): bool
    {
        $missingIds = [];
        $missingCodes = [];

        if ($ids !== []) {
            $foundIds = CleaningBooking::query()
                ->whereIn('id', $ids)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $missingIds = array_values(array_diff($ids, $foundIds));
        }

        if ($codes !== []) {
            $foundCodes = CleaningBooking::query()
                ->whereIn('booking_number', $codes)
                ->pluck('booking_number')
                ->map(static fn (mixed $code): string => (string) $code)
                ->all();
            $missingCodes = array_values(array_diff($codes, $foundCodes));
        }

        if ($missingIds === [] && $missingCodes === []) {
            return true;
        }

        if ($except) {
            if ($missingIds !== []) {
                $this->error('Cannot use --except: cleaning order IDs not found: '.implode(', ', $missingIds));
            }

            if ($missingCodes !== []) {
                $this->error('Cannot use --except: cleaning order codes not found: '.implode(', ', $missingCodes));
            }

            $this->error('Nothing was deleted because every --except selector must exist.');

            return false;
        }

        if ($missingIds !== []) {
            $this->warn('Cleaning orders not found by ID: '.implode(', ', $missingIds));
        }

        if ($missingCodes !== []) {
            $this->warn('Cleaning orders not found by code: '.implode(', ', $missingCodes));
        }

        return true;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $codes
     */
    private function selectedOrdersQuery(bool $deleteAll, bool $except, array $ids, array $codes): Builder
    {
        $query = CleaningBooking::query()->orderBy('id');

        if ($deleteAll) {
            return $query;
        }

        if ($except) {
            if ($ids !== []) {
                $query->whereNotIn('id', $ids);
            }

            if ($codes !== []) {
                $query->whereNotIn('booking_number', $codes);
            }

            return $query;
        }

        if ($ids !== [] && $codes !== []) {
            $query->where(static function (Builder $selectorQuery) use ($ids, $codes): void {
                $selectorQuery
                    ->whereIn('id', $ids)
                    ->orWhereIn('booking_number', $codes);
            });
        } elseif ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->whereIn('booking_number', $codes);
        }

        return $query;
    }

    private function accumulateLinkedCounts(CleaningBooking $booking): void
    {
        $this->totals['disputes'] += $booking->disputes()->count();
        $this->totals['sosAlerts'] += $booking->sosAlerts()->count();
        $this->totals['supportCases'] += $this->supportCasesForBooking($booking)->count();
        $this->totals['systemAlerts'] += $booking->systemAlerts()->count();
        $this->totals['statusLogs'] += $booking->statusLogs()->count();
        $this->totals['reviews'] += $booking->reviews()->count();
        $this->totals['ratings'] += $booking->ratings()->count();
        $this->totals['timeWarnings'] += $booking->timeWarnings()->count();
        $this->totals['serviceLinks'] += $booking->services()->count();
        $this->totals['securityCodes'] += $this->securityCodeQuery($booking)?->count() ?? 0;

        foreach (self::DIRECT_LINKED_TABLES as $key => $link) {
            if (! Schema::hasTable($link['table']) || ! Schema::hasColumn($link['table'], $link['column'])) {
                continue;
            }

            $this->totals[$key] += DB::table($link['table'])
                ->where($link['column'], $booking->id)
                ->count();
        }
    }

    private function deleteLinkedData(CleaningBooking $booking): void
    {
        // Support cases and disputes are deleted as models so Spatie Media Library
        // removes their attached files. Their message/event rows cascade in the DB.
        $this->supportCasesForBooking($booking)->get()->each(static function (SupportCase $supportCase): void {
            $supportCase->delete();
        });

        $booking->disputes()->get()->each(static function (Dispute $dispute): void {
            $dispute->delete();
        });

        $booking->sosAlerts()->delete();
        $booking->systemAlerts()->delete();
        $booking->statusLogs()->delete();
        $booking->reviews()->delete();
        $booking->ratings()->delete();
        $booking->timeWarnings()->delete();
        $booking->services()->detach();
        $this->securityCodeQuery($booking)?->delete();

        // Most of these tables already cascade on booking deletion. Explicitly
        // deleting them makes the cleanup deterministic on older production DBs.
        foreach (self::DIRECT_LINKED_TABLES as $link) {
            if (! Schema::hasTable($link['table']) || ! Schema::hasColumn($link['table'], $link['column'])) {
                continue;
            }

            DB::table($link['table'])
                ->where($link['column'], $booking->id)
                ->delete();
        }
    }

    private function supportCasesForBooking(CleaningBooking $booking): Builder
    {
        return SupportCase::query()
            ->where('booking_id', $booking->id)
            ->whereIn('booking_type', array_values(array_unique([
                $booking->getMorphClass(),
                CleaningBooking::class,
                'cleaning_booking',
            ])));
    }

    private function securityCodeQuery(CleaningBooking $booking): ?\Illuminate\Database\Query\Builder
    {
        if (! Schema::hasTable('booking_security_codes')) {
            return null;
        }

        return DB::table('booking_security_codes')
            ->where('booking_id', $booking->id)
            ->where('booking_type', $booking->getMorphClass());
    }

    /** @return array<string, int> */
    private function emptyTotals(): array
    {
        return [
            'orders' => 0,
            'disputes' => 0,
            'sosAlerts' => 0,
            'supportCases' => 0,
            'systemAlerts' => 0,
            'statusLogs' => 0,
            'reviews' => 0,
            'ratings' => 0,
            'timeWarnings' => 0,
            'serviceLinks' => 0,
            'securityCodes' => 0,
            'bookingAddons' => 0,
            'rooms' => 0,
            'workerAssignments' => 0,
            'workerRejections' => 0,
            'priceAdjustmentRequests' => 0,
            'notificationDispatches' => 0,
            'financialPenalties' => 0,
            'workerTrustLogs' => 0,
        ];
    }

    private function renderSummary(string $action): void
    {
        $rows = [];

        foreach ($this->totals as $type => $count) {
            $rows[] = [$type, $count];
        }

        $this->table(["{$action} data", 'Count'], $rows);
    }
}
