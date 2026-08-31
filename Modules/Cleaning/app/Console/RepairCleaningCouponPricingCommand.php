<?php

declare(strict_types=1);

namespace Modules\Cleaning\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningCouponPricingService;
use Throwable;

final class RepairCleaningCouponPricingCommand extends Command
{
    protected $signature = 'cleaning:repair-coupon-pricing
        {--apply : Persist the fixes. Without this flag the command is a dry run}
        {--ids=* : Cleaning booking IDs; repeat the option or pass comma-separated IDs}
        {--codes=* : Cleaning booking numbers; repeat the option or pass comma-separated codes}
        {--coupon-codes=* : Coupon codes; repeat the option or pass comma-separated codes}';

    protected $description = 'Repair legacy cleaning coupon pricing snapshots and worker assignment amounts using the current admin-first coupon rule';

    public function __construct(
        private readonly CleaningCouponPricingService $couponPricingService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $ids = $this->resolvePositiveIntegerOption('ids');
        $codes = $this->resolveStringOption('codes');
        $couponCodes = $this->resolveStringOption('coupon-codes');

        $query = $this->targetQuery($ids, $codes, $couponCodes);
        $completedCount = (clone $query)
            ->where('status', CleaningBookingStatus::Completed->value)
            ->count();

        // Completed bookings may already have commission transactions posted to the
        // worker financial ledger. Updating only the booking snapshot would create
        // a ledger mismatch, so this command intentionally leaves them untouched.
        $query->where('status', '!=', CleaningBookingStatus::Completed->value);

        $matchedCount = (clone $query)->count();

        if ($completedCount > 0) {
            $this->warn(
                "{$completedCount} completed coupon booking(s) were skipped because their posted financial ledger requires a separate reconciliation."
            );
        }

        if ($matchedCount === 0) {
            $this->info('No non-completed cleaning bookings with coupons matched the selected filters.');

            return self::SUCCESS;
        }

        $stats = [
            'matched' => $matchedCount,
            'changed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $rows = [];

        (clone $query)->chunkById(100, function ($bookings) use ($apply, &$stats, &$rows): void {
            foreach ($bookings as $booking) {
                if (! $booking instanceof CleaningBooking) {
                    continue;
                }

                try {
                    if ($apply) {
                        $result = $this->repairBooking((int) $booking->id);
                    } else {
                        $result = $this->previewBooking($booking);
                    }

                    $status = (string) $result['status'];
                    if (array_key_exists($status, $stats)) {
                        $stats[$status]++;
                    }

                    if (count($rows) < 100) {
                        $rows[] = $this->resultRow($booking, $result);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $stats['failed']++;

                    if (count($rows) < 100) {
                        $rows[] = [
                            $booking->id,
                            $booking->booking_number,
                            $booking->platform_coupon_code,
                            number_format((float) ($booking->discount_amount ?? 0), 2),
                            '-',
                            number_format((float) ($booking->admin_margin_amount ?? 0), 2),
                            '-',
                            number_format((float) ($booking->total_price ?? 0), 2),
                            '-',
                            'failed',
                        ];
                    }

                    $this->error("Booking #{$booking->id} failed: {$exception->getMessage()}");
                }
            }
        });

        if ($rows !== []) {
            $this->table([
                'ID',
                'Booking',
                'Coupon',
                'Old discount',
                'New discount',
                'Old admin',
                'New admin',
                'Old total',
                'New total',
                'Status',
            ], $rows);
        }

        if ($matchedCount > 100) {
            $this->line('Only the first 100 booking rows are shown; the summary includes all matched bookings.');
        }

        $mode = $apply ? 'APPLY' : 'DRY RUN';
        $this->info(
            sprintf(
                '%s complete. matched=%d changed=%d unchanged=%d skipped=%d failed=%d',
                $mode,
                $stats['matched'],
                $stats['changed'],
                $stats['unchanged'],
                $stats['skipped'],
                $stats['failed'],
            )
        );

        if (! $apply && $stats['changed'] > 0) {
            $this->warn('Review the preview, take a database backup, then run the same command with --apply.');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $codes
     * @param  array<int, string>  $couponCodes
     */
    private function targetQuery(array $ids, array $codes, array $couponCodes): Builder
    {
        return CleaningBooking::query()
            ->whereNotNull('platform_coupon_id')
            ->where('platform_coupon_id', '>', 0)
            ->when($ids !== [], fn (Builder $query): Builder => $query->whereIn('id', $ids))
            ->when($codes !== [], fn (Builder $query): Builder => $query->whereIn('booking_number', $codes))
            ->when($couponCodes !== [], fn (Builder $query): Builder => $query->whereIn('platform_coupon_code', $couponCodes))
            ->orderBy('id');
    }

    /**
     * @return array{
     *     status:string,
     *     discount:float,
     *     admin:float,
     *     total:float
     * }
     */
    private function previewBooking(CleaningBooking $booking): array
    {
        $breakdown = $this->couponPricingService->storedBreakdown($booking);

        if (! is_array($breakdown)) {
            return [
                'status' => 'skipped',
                'discount' => (float) ($booking->discount_amount ?? 0),
                'admin' => (float) ($booking->admin_margin_amount ?? 0),
                'total' => (float) ($booking->total_price ?? 0),
            ];
        }

        $expectedDiscount = round((float) $breakdown['discountAmount'], 2);
        $expectedAdmin = round((float) $breakdown['adminMargin'], 2);
        $expectedTotal = round((float) $breakdown['totalPrice'], 2);
        $changed = $this->moneyChanged((float) ($booking->discount_amount ?? 0), $expectedDiscount)
            || $this->moneyChanged((float) ($booking->admin_margin_amount ?? 0), $expectedAdmin)
            || $this->moneyChanged((float) ($booking->total_price ?? 0), $expectedTotal)
            || $this->legacyAssignmentPricingExists($booking);

        return [
            'status' => $changed ? 'changed' : 'unchanged',
            'discount' => $expectedDiscount,
            'admin' => $expectedAdmin,
            'total' => $expectedTotal,
        ];
    }

    /**
     * @return array{
     *     status:string,
     *     discount:float,
     *     admin:float,
     *     total:float
     * }
     */
    private function repairBooking(int $bookingId): array
    {
        return DB::transaction(function () use ($bookingId): array {
            $booking = CleaningBooking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->first();

            if (! $booking instanceof CleaningBooking) {
                return [
                    'status' => 'skipped',
                    'discount' => 0.0,
                    'admin' => 0.0,
                    'total' => 0.0,
                ];
            }

            if ($booking->status === CleaningBookingStatus::Completed) {
                return [
                    'status' => 'skipped',
                    'discount' => (float) ($booking->discount_amount ?? 0),
                    'admin' => (float) ($booking->admin_margin_amount ?? 0),
                    'total' => (float) ($booking->total_price ?? 0),
                ];
            }

            $before = $this->financialSnapshot($booking);

            $this->couponPricingService->applyBeforeSave($booking);
            $booking->saveQuietly();
            $booking->refresh();

            $after = $this->financialSnapshot($booking);
            $changed = $before !== $after;

            return [
                'status' => $changed ? 'changed' : 'unchanged',
                'discount' => (float) $booking->discount_amount,
                'admin' => (float) $booking->admin_margin_amount,
                'total' => (float) $booking->total_price,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function financialSnapshot(CleaningBooking $booking): array
    {
        $assignments = $booking->workerAssignments()
            ->orderBy('id')
            ->get([
                'id',
                'service_share_amount',
                'travel_fee',
                'admin_margin_amount',
                'worker_amount',
            ])
            ->map(static fn ($assignment): array => [
                'id' => (int) $assignment->id,
                'serviceShare' => round((float) ($assignment->service_share_amount ?? 0), 2),
                'travelFee' => round((float) ($assignment->travel_fee ?? 0), 2),
                'adminMargin' => round((float) ($assignment->admin_margin_amount ?? 0), 2),
                'workerAmount' => round((float) ($assignment->worker_amount ?? 0), 2),
            ])
            ->all();

        return [
            'subtotalBeforeDiscount' => round((float) ($booking->subtotal_before_discount ?? 0), 2),
            'discountAmount' => round((float) ($booking->discount_amount ?? 0), 2),
            'adminMargin' => round((float) ($booking->admin_margin_amount ?? 0), 2),
            'totalPrice' => round((float) ($booking->total_price ?? 0), 2),
            'assignments' => $assignments,
        ];
    }

    private function legacyAssignmentPricingExists(CleaningBooking $booking): bool
    {
        $breakdown = $this->couponPricingService->storedBreakdown($booking);
        if (! is_array($breakdown)) {
            return false;
        }

        $grossService = max(0.0, (float) ($breakdown['grossServiceAmount'] ?? 0));
        $serviceNetFactor = max(0.0, min(1.0, (float) ($breakdown['assignmentWorkerNetFactor'] ?? 1)));

        if ($serviceNetFactor >= 1.0 || $grossService <= 0.0) {
            return false;
        }

        return $booking->workerAssignments()
            ->where('service_share_amount', '<', $grossService)
            ->exists();
    }

    /** @param array<string, mixed> $result */
    private function resultRow(CleaningBooking $booking, array $result): array
    {
        return [
            $booking->id,
            $booking->booking_number,
            $booking->platform_coupon_code,
            number_format((float) ($booking->discount_amount ?? 0), 2),
            number_format((float) $result['discount'], 2),
            number_format((float) ($booking->admin_margin_amount ?? 0), 2),
            number_format((float) $result['admin'], 2),
            number_format((float) ($booking->total_price ?? 0), 2),
            number_format((float) $result['total'], 2),
            $result['status'],
        ];
    }

    private function moneyChanged(float $left, float $right): bool
    {
        return abs($left - $right) > 0.01;
    }

    /** @return array<int, int> */
    private function resolvePositiveIntegerOption(string $option): array
    {
        $values = [];

        foreach ((array) $this->option($option) as $raw) {
            foreach (explode(',', (string) $raw) as $value) {
                $value = trim($value);

                if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                    $values[] = (int) $value;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /** @return array<int, string> */
    private function resolveStringOption(string $option): array
    {
        $values = [];

        foreach ((array) $this->option($option) as $raw) {
            foreach (explode(',', (string) $raw) as $value) {
                $value = trim($value);

                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
    }
}
