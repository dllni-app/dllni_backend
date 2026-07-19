<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('platform_coupon_id')->nullable()->after('promo_code_id')
                ->constrained('platform_coupons')->nullOnDelete();
            $table->string('platform_coupon_code', 50)->nullable()->after('platform_coupon_id');
        });

        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->foreignId('platform_coupon_id')->nullable()->after('coupon_id')
                ->constrained('platform_coupons')->nullOnDelete();
            $table->string('platform_coupon_code', 50)->nullable()->after('platform_coupon_id');
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->foreignId('platform_coupon_id')->nullable()->after('billing_policy_id')
                ->constrained('platform_coupons')->nullOnDelete();
            $table->string('platform_coupon_code', 50)->nullable()->after('platform_coupon_id');
            $table->decimal('subtotal_before_discount', 12, 2)->nullable()->after('platform_coupon_code');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal_before_discount');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('platform_coupon_id');
            $table->dropColumn(['platform_coupon_code', 'subtotal_before_discount', 'discount_amount']);
        });

        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('platform_coupon_id');
            $table->dropColumn('platform_coupon_code');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('platform_coupon_id');
            $table->dropColumn('platform_coupon_code');
        });
    }
};
