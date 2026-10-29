<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.17 additive integrity guard. The initial 02:00 migration already ran
 * on the authorized local database; do not edit or replay its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Never silently discard conflicting financial evidence to make a
        // unique index pass. Such rows require an independent reconciliation.
        $duplicates = DB::table('cleaning_visit_funding_reservations')
            ->select('cleaning_booking_session_id')
            ->where('active_slot', 1)
            ->groupBy('cleaning_booking_session_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Conflicting active visit funding reservations require reconciliation.');
        }

        Schema::table('cleaning_visit_funding_reservations', static function (Blueprint $table): void {
            // MySQL permits multiple NULL slots for released/history rows;
            // exactly one active slot=1 can exist across ALL funding sources.
            $table->unique(['cleaning_booking_session_id', 'active_slot'], 'cvfr_visit_active_uq');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_visit_funding_reservations', static function (Blueprint $table): void {
            $table->dropUnique('cvfr_visit_active_uq');
        });
    }
};
