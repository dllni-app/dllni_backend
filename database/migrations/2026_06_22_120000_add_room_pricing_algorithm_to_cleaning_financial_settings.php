<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_base_unit_price')) {
                $table->decimal('cleaning_base_unit_price', 12, 2)->default(30000);
            }

            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_deep_multiplier')) {
                $table->decimal('cleaning_deep_multiplier', 5, 2)->default(4);
            }

            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_area_margin_multiplier')) {
                $table->decimal('cleaning_area_margin_multiplier', 5, 2)->default(1.18);
            }

            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_setup_buffer_minutes')) {
                $table->unsignedSmallInteger('cleaning_setup_buffer_minutes')->default(22);
            }

            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_room_size_ranges')) {
                $table->json('cleaning_room_size_ranges')->nullable();
            }

            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_room_pricing_units')) {
                $table->json('cleaning_room_pricing_units')->nullable();
            }

            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_room_time_minutes')) {
                $table->json('cleaning_room_time_minutes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            foreach ([
                'cleaning_base_unit_price',
                'cleaning_deep_multiplier',
                'cleaning_area_margin_multiplier',
                'cleaning_setup_buffer_minutes',
                'cleaning_room_size_ranges',
                'cleaning_room_pricing_units',
                'cleaning_room_time_minutes',
            ] as $column) {
                if (Schema::hasColumn('cleaning_financial_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
