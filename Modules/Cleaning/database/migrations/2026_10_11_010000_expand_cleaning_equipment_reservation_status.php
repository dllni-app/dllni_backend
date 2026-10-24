<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Phase 3 introduces 'failure_pending_confirmation' (28 characters).
        // Use an additive migration so databases which already applied Phase 3
        // receive this schema correction as well as fresh installations.
        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->string('status', 48)->default('reserved')->change();
        });
    }

    public function down(): void
    {
        // Never silently truncate persisted Phase 3 state values during rollback.
        // Deployment rollback is application-only once pending return records exist.
        if (\Illuminate\Support\Facades\DB::table('cleaning_equipment_reservations')
            ->whereRaw('CHAR_LENGTH(status) > 24')->exists()) {
            throw new \RuntimeException('Cannot narrow equipment status: Phase 3 return states must be reconciled first.');
        }

        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->string('status', 24)->default('reserved')->change();
        });
    }
};
