<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_coupons', function (Blueprint $table): void {
            $table->string('funding_type', 20)->default('platform')->after('min_order_amount');
            $table->decimal('platform_funding_percent', 5, 2)->default(100)->after('funding_type');
            $table->decimal('merchant_funding_percent', 5, 2)->default(0)->after('platform_funding_percent');
            $table->decimal('max_platform_funding_amount', 12, 2)->nullable()->after('merchant_funding_percent');
            $table->boolean('cap_platform_funding_to_revenue')->default(false)->after('max_platform_funding_amount');
        });

        Schema::table('platform_coupon_redemptions', function (Blueprint $table): void {
            $table->string('funding_type', 20)->nullable()->after('discount_amount');
            $table->decimal('platform_funded_amount', 12, 2)->default(0)->after('funding_type');
            $table->decimal('merchant_funded_amount', 12, 2)->default(0)->after('platform_funded_amount');
            $table->json('funding_snapshot')->nullable()->after('merchant_funded_amount');
            $table->timestamp('reversed_at')->nullable()->after('redeemed_at');
            $table->foreignId('reversed_by_user_id')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by_user_id');
            $table->index(['platform_coupon_id', 'reversed_at'], 'platform_coupon_redemption_active_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('merchant_gross_amount', 14, 2)->nullable()->after('service_fee');
            $table->decimal('commission_amount', 14, 2)->nullable()->after('merchant_gross_amount');
            $table->decimal('merchant_net_amount', 14, 2)->nullable()->after('commission_amount');
            $table->decimal('coupon_platform_funded_amount', 14, 2)->nullable()->after('merchant_net_amount');
            $table->decimal('coupon_merchant_funded_amount', 14, 2)->nullable()->after('coupon_platform_funded_amount');
            $table->decimal('platform_gross_revenue', 14, 2)->nullable()->after('coupon_merchant_funded_amount');
            $table->decimal('platform_coupon_cost', 14, 2)->nullable()->after('platform_gross_revenue');
            $table->decimal('platform_net_revenue', 14, 2)->nullable()->after('platform_coupon_cost');
            $table->json('financial_snapshot')->nullable()->after('platform_net_revenue');
            $table->timestamp('financial_reversed_at')->nullable()->after('financial_snapshot');
        });
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->decimal('merchant_gross_amount', 14, 2)->nullable()->after('commission_base_amount');
            $table->decimal('merchant_net_amount', 14, 2)->nullable()->after('store_net_amount');
            $table->decimal('coupon_platform_funded_amount', 14, 2)->nullable()->after('merchant_net_amount');
            $table->decimal('coupon_merchant_funded_amount', 14, 2)->nullable()->after('coupon_platform_funded_amount');
            $table->decimal('platform_gross_revenue', 14, 2)->nullable()->after('coupon_merchant_funded_amount');
            $table->decimal('platform_coupon_cost', 14, 2)->nullable()->after('platform_gross_revenue');
            $table->decimal('platform_net_revenue', 14, 2)->nullable()->after('platform_coupon_cost');
            $table->json('financial_snapshot')->nullable()->after('commission_snapshot');
            $table->timestamp('financial_reversed_at')->nullable()->after('financial_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'merchant_gross_amount',
                'merchant_net_amount',
                'coupon_platform_funded_amount',
                'coupon_merchant_funded_amount',
                'platform_gross_revenue',
                'platform_coupon_cost',
                'platform_net_revenue',
                'financial_snapshot',
                'financial_reversed_at',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'merchant_gross_amount',
                'commission_amount',
                'merchant_net_amount',
                'coupon_platform_funded_amount',
                'coupon_merchant_funded_amount',
                'platform_gross_revenue',
                'platform_coupon_cost',
                'platform_net_revenue',
                'financial_snapshot',
                'financial_reversed_at',
            ]);
        });

        Schema::table('platform_coupon_redemptions', function (Blueprint $table): void {
            $table->dropIndex('platform_coupon_redemption_active_idx');
            $table->dropConstrainedForeignId('reversed_by_user_id');
            $table->dropColumn([
                'funding_type',
                'platform_funded_amount',
                'merchant_funded_amount',
                'funding_snapshot',
                'reversed_at',
                'reversal_reason',
            ]);
        });

        Schema::table('platform_coupons', function (Blueprint $table): void {
            $table->dropColumn([
                'funding_type',
                'platform_funding_percent',
                'merchant_funding_percent',
                'max_platform_funding_amount',
                'cap_platform_funding_to_revenue',
            ]);
        });
    }
};
