<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table) {
            $table->string('commission_type')->default('percent')->after('default_commission_rate');
            $table->decimal('commission_fixed_amount', 10, 2)->nullable()->after('commission_type');
            $table->string('travel_distance_start_point')->default('auto')->after('travel_markup_value');
            $table->string('time_billing_mode')->default('actual')->after('coverage_thresholds');
            $table->unsignedInteger('min_billable_minutes')->nullable()->after('time_billing_mode');
            $table->unsignedSmallInteger('time_warning_minutes_before_end')->nullable()->after('min_billable_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table) {
            $table->dropColumn([
                'commission_type',
                'commission_fixed_amount',
                'travel_distance_start_point',
                'time_billing_mode',
                'min_billable_minutes',
                'time_warning_minutes_before_end',
            ]);
        });
    }
};
