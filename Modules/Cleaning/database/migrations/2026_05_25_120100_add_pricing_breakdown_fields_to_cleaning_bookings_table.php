<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_bookings', 'travel_distance_km')) {
                $table->decimal('travel_distance_km', 8, 3)->nullable()->after('travel_fee');
            }

            if (! Schema::hasColumn('cleaning_bookings', 'admin_margin_amount')) {
                $table->decimal('admin_margin_amount', 10, 2)->default(0)->after('travel_distance_km');
            }

            if (! Schema::hasColumn('cleaning_bookings', 'is_pricing_final')) {
                $table->boolean('is_pricing_final')->default(true)->after('admin_margin_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('cleaning_bookings', 'is_pricing_final')) {
                $columns[] = 'is_pricing_final';
            }

            if (Schema::hasColumn('cleaning_bookings', 'admin_margin_amount')) {
                $columns[] = 'admin_margin_amount';
            }

            if (Schema::hasColumn('cleaning_bookings', 'travel_distance_km')) {
                $columns[] = 'travel_distance_km';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
