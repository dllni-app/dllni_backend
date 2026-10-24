<?php

declare(strict_types=1);

namespace Modules\Cleaning\Console;

use Illuminate\Console\Command;
use Modules\Cleaning\Services\CleaningBookingVisitInitializationService;

final class InitializeCleaningBookingVisitsCommand extends Command
{
    protected $signature = 'cleaning:initialize-v2-visits {--booking-ids=* : Explicit cleaning booking IDs} {--apply : Persist safe initialization changes}';

    protected $description = 'Preview or explicitly initialize safe Cleaning V2 visit foundations.';

    public function handle(CleaningBookingVisitInitializationService $initialization): int
    {
        $bookingIds = collect((array) $this->option('booking-ids'))
            ->filter(static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0)
            ->map(static fn (mixed $value): int => (int) $value)
            ->unique()
            ->values();
        $apply = (bool) $this->option('apply');

        if ($apply && $bookingIds->isEmpty()) {
            $this->error('Apply mode requires at least one explicit --booking-ids value.');

            return self::FAILURE;
        }

        if ($bookingIds->isEmpty()) {
            $this->warn('No booking IDs supplied. Preview made no selections.');

            return self::SUCCESS;
        }

        $results = $bookingIds
            ->map(fn (int $bookingId): array => $initialization->initializeBooking($bookingId, $apply))
            ->all();

        $this->line(json_encode([
            'mode' => $apply ? 'apply' : 'dry-run',
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return collect($results)->contains(
            static fn (array $result): bool => ($result['status'] ?? null) === 'skipped'
                && ($result['reason'] ?? null) === 'booking_not_found'
        ) ? self::FAILURE : self::SUCCESS;
    }
}
