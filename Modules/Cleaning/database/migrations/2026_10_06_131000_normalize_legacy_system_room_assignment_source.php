<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_booking_rooms')) {
            return;
        }

        DB::table('cleaning_booking_rooms')
            ->where('assignment_source', 'system')
            ->update(['assignment_source' => 'auto']);
    }

    public function down(): void
    {
        // Intentionally not reversible: "auto" is the canonical value and
        // legacy "system" rows cannot be distinguished from native auto rows.
    }
};
