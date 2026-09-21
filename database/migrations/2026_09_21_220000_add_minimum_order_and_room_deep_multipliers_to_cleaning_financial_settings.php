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
            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_minimum_order_price')) {
                $table->decimal('cleaning_minimum_order_price', 12, 2)->default(150)
                    ->after('cleaning_base_unit_price');
            }

            if (! Schema::hasColumn('cleaning_financial_settings', 'cleaning_room_deep_multipliers')) {
                $table->json('cleaning_room_deep_multipliers')->nullable()
                    ->after('cleaning_room_pricing_units');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_financial_settings', 'cleaning_room_deep_multipliers')) {
                $table->dropColumn('cleaning_room_deep_multipliers');
            }

            if (Schema::hasColumn('cleaning_financial_settings', 'cleaning_minimum_order_price')) {
                $table->dropColumn('cleaning_minimum_order_price');
            }
        });
    }
};
