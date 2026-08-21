<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_bookings')) {
            return;
        }

        if (! Schema::hasColumn('cleaning_bookings', 'platform_coupon_id')) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->unsignedBigInteger('platform_coupon_id')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('cleaning_bookings', 'platform_coupon_code')) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->string('platform_coupon_code', 50)->nullable();
            });
        }

        if (! Schema::hasColumn('cleaning_bookings', 'subtotal_before_discount')) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->decimal('subtotal_before_discount', 12, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('cleaning_bookings', 'discount_amount')) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->decimal('discount_amount', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: these columns belong to the canonical
        // platform coupon migration and may already exist on healthy databases.
    }
};
