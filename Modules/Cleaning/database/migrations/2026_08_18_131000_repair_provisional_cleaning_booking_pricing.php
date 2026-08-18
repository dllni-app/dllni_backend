<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_bookings') || ! Schema::hasTable('cleaning_financial_settings')) {
            return;
        }

        $financial = DB::table('cleaning_financial_settings')->orderBy('id')->first();
        if ($financial === null) {
            return;
        }

        $commissionType = (string) ($financial->commission_type ?? 'percent');
        $commissionRate = max(0.0, (float) ($financial->default_commission_rate ?? 0));
        $fixedCommission = max(0.0, (float) ($financial->commission_fixed_amount ?? 0));

        DB::table('cleaning_bookings')
            ->where('status', 'pending')
            ->where('is_pricing_final', false)
            ->where(function ($query): void {
                $query->whereNull('platform_coupon_id')->orWhere('platform_coupon_id', 0);
            })
            ->where(function ($query): void {
                $query->whereNull('extension_fee_total')->orWhere('extension_fee_total', '<=', 0);
            })
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use ($commissionType, $commissionRate, $fixedCommission): void {
                foreach ($bookings as $booking) {
                    $basePrice = max(0.0, (float) ($booking->base_price ?? 0));
                    $addonsTotal = max(0.0, (float) ($booking->addons_total ?? 0));
                    $serviceSubtotal = (float) ceil($basePrice + $addonsTotal);
                    $adminMargin = $commissionType === 'fixed'
                        ? (float) ceil($fixedCommission)
                        : (float) ceil($serviceSubtotal * ($commissionRate / 100));

                    DB::table('cleaning_bookings')
                        ->where('id', $booking->id)
                        ->update([
                            'travel_fee' => 0,
                            'travel_distance_km' => null,
                            'admin_margin_amount' => $adminMargin,
                            'total_price' => (float) ceil($serviceSubtotal + $adminMargin),
                            'is_pricing_final' => false,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Pricing snapshots cannot be safely reconstructed to their previous incorrect values.
    }
};
