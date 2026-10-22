<?php

declare(strict_types=1);

namespace Modules\Cleaning\Console;

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningFinancialPenalty;
use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingPriceAdjustmentRequest;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningCouponPricingService;
use RuntimeException;
use Throwable;

/**
 * Reconcile one pre-fix order that incorrectly added commission on top of an
 * already commission-inclusive minimum. Never automatically scan other orders.
 */
final class RepairInclusiveCouponBookingCommand extends Command
{
    protected $signature = 'cleaning:repair-inclusive-coupon
        {booking : Numeric cleaning booking ID}
        {--booking-number= : Exact booking number, required to prevent editing a different order}
        {--service= : Original commission-inclusive service amount}
        {--travel= : Undiscounted transportation charge}
        {--gross-admin= : Commission contained in the original service amount}
        {--expected-total= : Current (incorrect) customer total for optimistic validation}
        {--apply : Actually persist the proposed correction; otherwise preview only}';

    protected $description = 'Preview or safely repair one legacy commission-inclusive cleaning coupon booking and its worker/coupon snapshots';

    public function __construct(private readonly CleaningCouponPricingService $couponPricing)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $bookingId = filter_var($this->argument('booking'), FILTER_VALIDATE_INT);
            if (! is_int($bookingId) || $bookingId <= 0) {
                throw new RuntimeException('Provide a positive numeric booking ID.');
            }

            $bookingNumber = trim((string) $this->option('booking-number'));
            if ($bookingNumber === '') {
                throw new RuntimeException('--booking-number is required.');
            }

            $service = $this->amount('service');
            $travel = $this->amount('travel');
            $grossAdmin = $this->amount('gross-admin');
            $expectedTotal = $this->amount('expected-total');
            if ($service <= 0 || $grossAdmin <= 0 || $grossAdmin > $service) {
                throw new RuntimeException('Service and gross commission must be positive; commission cannot exceed service.');
            }

            $apply = (bool) $this->option('apply');

            $result = DB::transaction(function () use (
                $bookingId, $bookingNumber, $service, $travel, $grossAdmin, $expectedTotal, $apply
            ): array {
                $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
                if ($booking->booking_number !== $bookingNumber) {
                    throw new RuntimeException('Booking number mismatch; no records changed.');
                }

                if (! in_array($booking->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true)
                    || $booking->work_started_at !== null || $booking->started_travel_at !== null
                    || $booking->arrived_at !== null || $booking->work_finished_at !== null) {
                    throw new RuntimeException('Work has started or booking is finalized; reconcile the ledger manually instead.');
                }

                if ((int) $booking->number_of_workers !== 1
                    || (float) $booking->addons_total !== 0.0
                    || (float) ($booking->extension_fee_total ?? 0) !== 0.0
                    || ! (bool) $booking->is_pricing_final
                    || (string) $booking->property_type === 'event_assistance'
                    || (string) ($booking->booking_kind ?? 'standard') !== 'standard') {
                    throw new RuntimeException('Only finalized-price, single-worker, standard cleaning without add-ons or extensions is supported.');
                }

                if (method_exists($booking, 'sessions') && $booking->sessions()->exists()) {
                    throw new RuntimeException('Visit/session bookings need separate reconciliation; no changes made.');
                }

                $assignments = $booking->workerAssignments()->lockForUpdate()->get();
                if ($assignments->count() !== 1) {
                    throw new RuntimeException('Exactly one worker assignment must exist.');
                }
                /** @var CleaningBookingWorkerAssignment $assignment */
                $assignment = $assignments->first();
                $assignmentStatus = $assignment->status?->value ?? (string) $assignment->status;
                if (! in_array($assignmentStatus, ['pending', 'accepted', 'accepted_waiting_for_order_start'], true)
                    || $assignment->work_started_at !== null || $assignment->started_travel_at !== null
                    || $assignment->arrived_at !== null || $assignment->work_finished_at !== null) {
                    throw new RuntimeException('Worker already started the lifecycle; financial correction requires review.');
                }

                $coupon = PlatformCoupon::query()->find((int) $booking->platform_coupon_id);
                if (! $coupon instanceof PlatformCoupon
                    || (string) $coupon->code !== (string) $booking->platform_coupon_code
                    || (string) $coupon->section !== PlatformCoupon::SECTION_CLEANING
                    || (string) $coupon->discount_type !== PlatformCoupon::DISCOUNT_PERCENTAGE
                    || abs((float) $coupon->discount_value - 10.0) > 0.001) {
                    throw new RuntimeException('Expected an attached 10% cleaning coupon; found a different coupon.');
                }

                $redemptions = PlatformCouponRedemption::query()
                    ->where('platform_coupon_id', $coupon->id)
                    ->where('section', PlatformCoupon::SECTION_CLEANING)
                    ->where('order_type', $booking->getMorphClass())
                    ->where('order_id', $booking->id)
                    ->lockForUpdate()->get();
                if ($redemptions->count() !== 1) {
                    throw new RuntimeException('Exactly one matching coupon redemption is required.');
                }
                /** @var PlatformCouponRedemption $redemption */
                $redemption = $redemptions->first();
                if ((int) $redemption->user_id !== (int) $booking->customer_id
                    || (string) $redemption->coupon_code !== (string) $booking->platform_coupon_code) {
                    throw new RuntimeException('Coupon redemption does not match the booking customer and code.');
                }

                $reference = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX
                    .hash('sha256', $assignment->worker_id.':'.$booking->id);
                if (CleaningDepositTransaction::query()->where('worker_id', $assignment->worker_id)
                    ->where('reference', $reference)->exists()
                    || CleaningFinancialPenalty::query()->where('cleaning_booking_id', $booking->id)->exists()
                    || CleaningBookingPriceAdjustmentRequest::query()->where('cleaning_booking_id', $booking->id)->exists()) {
                    throw new RuntimeException('Financial transactions, penalties, or price adjustments already exist. Do not modify financial snapshots.');
                }

                $proposed = $this->couponPricing->allocation($coupon, $service, $travel, $grossAdmin, $grossAdmin);
                if (! $this->equals($proposed['grossTotal'], $service + $travel)
                    || ! $this->equals($proposed['discountAmount'], round($service * .10, 2))
                    || ! $this->equals($proposed['totalPrice'], $service + $travel - $proposed['discountAmount'])
                    || ! $this->equals($proposed['serviceAmount'] + $proposed['travelFee'] + $proposed['adminMargin'], $proposed['totalPrice'])) {
                    throw new RuntimeException('Coupon allocation is inconsistent; refusing to change stored values.');
                }

                $details = (array) ($booking->property_details ?? []);
                $snapshot = $details['_coupon_pricing'] ?? null;
                $correct = $this->equals((float) $booking->subtotal_before_discount, $proposed['grossTotal'])
                    && $this->equals((float) $booking->discount_amount, $proposed['discountAmount'])
                    && $this->equals((float) $booking->total_price, $proposed['totalPrice'])
                    && $this->equals((float) $booking->admin_margin_amount, $proposed['adminMargin'])
                    && $this->equals((float) $assignment->service_share_amount, $proposed['serviceAmount'])
                    && $this->equals((float) $assignment->travel_fee, $travel)
                    && $this->equals((float) $assignment->admin_margin_amount, $proposed['adminMargin'])
                    && $this->equals((float) $assignment->worker_amount, $proposed['serviceAmount'] + $travel)
                    && is_array($snapshot)
                    && $this->equals((float) ($snapshot['grossAdminMargin'] ?? -1), $grossAdmin)
                    && $this->equals((float) ($snapshot['includedAdminMargin'] ?? -1), $grossAdmin)
                    && $this->equals((float) $redemption->subtotal, $proposed['grossTotal'])
                    && $this->equals((float) $redemption->discount_amount, $proposed['discountAmount']);

                if (! $this->equals((float) $booking->base_price, $service)
                    || ! $this->equals((float) $booking->travel_fee, $travel)) {
                    throw new RuntimeException('Base service or travel does not match the supplied amounts.');
                }

                $before = [
                    'subtotal' => (float) $booking->subtotal_before_discount,
                    'discount' => (float) $booking->discount_amount,
                    'admin' => (float) $booking->admin_margin_amount,
                    'total' => (float) $booking->total_price,
                    'worker' => (float) $assignment->worker_amount,
                ];
                $after = [
                    'subtotal' => $proposed['grossTotal'],
                    'discount' => $proposed['discountAmount'],
                    'admin' => $proposed['adminMargin'],
                    'total' => $proposed['totalPrice'],
                    'worker' => round($proposed['serviceAmount'] + $travel, 2),
                ];

                if (! $correct) {
                    $legacyGross = round($service + $travel + $grossAdmin, 2);
                    $legacyDiscount = round($legacyGross * .10, 2);
                    if (! $this->equals((float) $booking->total_price, $expectedTotal)
                        || ! $this->equals((float) $booking->subtotal_before_discount, $legacyGross)
                        || ! $this->equals((float) $booking->discount_amount, $legacyDiscount)
                        || ! $this->equals((float) $booking->admin_margin_amount, $grossAdmin - $legacyDiscount)
                        || ! $this->equals((float) $assignment->service_share_amount, $service)
                        || ! $this->equals((float) $assignment->travel_fee, $travel)
                        || ! $this->equals((float) $assignment->admin_margin_amount, $grossAdmin - $legacyDiscount)
                        || ! $this->equals((float) $assignment->worker_amount, $service + $travel)
                        || $snapshot !== null
                        || ! $this->equals((float) $redemption->subtotal, $legacyGross)
                        || ! $this->equals((float) $redemption->discount_amount, $legacyDiscount)) {
                        throw new RuntimeException('Historical price or worker values do not match the known double-commission case.');
                    }

                    if ($apply) {
                        $details['_coupon_pricing'] = [
                            'grossAdminMargin' => round($grossAdmin, 2),
                            'includedAdminMargin' => round($grossAdmin, 2),
                        ];
                        // Do not fire lifecycle/price observers during one-time reconciliation.
                        $booking->forceFill([
                            'property_details' => $details,
                            'subtotal_before_discount' => $after['subtotal'],
                            'discount_amount' => $after['discount'],
                            'admin_margin_amount' => $after['admin'],
                            'total_price' => $after['total'],
                        ])->saveQuietly();
                        $assignment->forceFill([
                            'service_share_amount' => $proposed['serviceAmount'],
                            'travel_fee' => $travel,
                            'admin_margin_amount' => $proposed['adminMargin'],
                            'worker_amount' => $after['worker'],
                        ])->saveQuietly();
                        $redemption->forceFill([
                            'subtotal' => $after['subtotal'],
                            'discount_amount' => $after['discount'],
                        ])->saveQuietly();
                    }
                }

                return ['before' => $before, 'after' => $after, 'status' => $correct ? 'ALREADY CORRECT' : ($apply ? 'APPLIED' : 'PREVIEW')];
            }, 3);

            if ($result['status'] === 'APPLIED') {
                Log::notice('Corrected commission-inclusive cleaning coupon booking', [
                    'booking_id' => $bookingId,
                    'booking_number' => $bookingNumber,
                    'before' => $result['before'],
                    'after' => $result['after'],
                ]);
            }

            $this->info('Booking '.$bookingNumber.' (#'.$bookingId.'): '.$result['status']);
            $this->table(['Field', 'Before', 'After'], collect($result['before'])->map(
                fn (float $old, string $field): array => [$field, number_format($old, 2), number_format((float) $result['after'][$field], 2)]
            )->values()->all());
            if (! $apply && $result['status'] === 'PREVIEW') {
                $this->warn('Read-only preview. Verify the financial figures and take a database backup before adding --apply.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('No changes made: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function amount(string $option): float
    {
        $raw = $this->option($option);
        if (! is_scalar($raw) || ! is_numeric($raw) || ! is_finite((float) $raw) || (float) $raw < 0) {
            throw new RuntimeException('--'.$option.' must be a non-negative numeric amount.');
        }

        return round((float) $raw, 2);
    }

    private function equals(float $left, float $right): bool
    {
        return abs(round($left, 2) - round($right, 2)) < .005;
    }
}
