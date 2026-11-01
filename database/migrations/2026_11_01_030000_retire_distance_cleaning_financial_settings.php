<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_financial_settings')) {
            return;
        }

        // Retain the saved allowance, including zero. Only retire the calculation mode.
        DB::table('cleaning_financial_settings')->update(['travel_markup_type' => 'worker_allowance']);

        // Keep legacy columns intact for historical data, old migration replays
        // and fixtures. They are not writable/readable by active pricing code.
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_financial_settings')) {
            return;
        }

        // Original transport mode is intentionally not restored: it is no
        // longer supported by the application and cannot be inferred safely.
    }
};
