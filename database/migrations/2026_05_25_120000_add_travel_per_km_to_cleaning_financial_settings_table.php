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
            if (! Schema::hasColumn('cleaning_financial_settings', 'travel_per_km')) {
                $table->decimal('travel_per_km', 10, 2)->default(7500)->after('travel_markup_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_financial_settings', 'travel_per_km')) {
                $table->dropColumn('travel_per_km');
            }
        });
    }
};
