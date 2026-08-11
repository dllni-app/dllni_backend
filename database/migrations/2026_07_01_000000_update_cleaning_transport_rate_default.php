<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_financial_settings') || ! Schema::hasColumn('cleaning_financial_settings', 'travel_per_km')) {
            return;
        }

        DB::table('cleaning_financial_settings')
            ->whereIn('travel_per_km', [0, 100])
            ->update(['travel_per_km' => 7500]);
    }

    public function down(): void
    {
        // Intentionally left empty because this setting may be edited by admins after deployment.
    }
};
