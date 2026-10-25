<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_special_services')) {
            return;
        }

        // Change only the exact legacy seeded values. Leave customized
        // durations, different pricing units and unrelated services untouched.
        foreach ([['carpet-wash', 20, 2], ['post-renovation', 180, 5]] as [$slug, $before, $after]) {
            DB::table('cleaning_special_services')
                ->where('slug', $slug)
                ->where('unit_code', 'sqm')
                ->where('estimated_duration_minutes', $before)
                ->update(['estimated_duration_minutes' => $after, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // This is a data correction, not a reversible change to administrator
        // configuration. Rolling back code must not reintroduce bad estimates.
    }
};
