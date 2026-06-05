<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_rooms', function (Blueprint $table): void {
            $table->unsignedInteger('planned_worker_slot')->nullable()->after('weight');
            $table->foreignId('planned_preferred_worker_id')
                ->nullable()
                ->after('planned_worker_slot')
                ->constrained('workers')
                ->nullOnDelete();

            $table->index(['cleaning_booking_id', 'planned_worker_slot'], 'cbr_booking_planned_slot_idx');
            $table->index(['cleaning_booking_id', 'planned_preferred_worker_id'], 'cbr_booking_planned_worker_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_rooms', function (Blueprint $table): void {
            $table->dropIndex('cbr_booking_planned_slot_idx');
            $table->dropIndex('cbr_booking_planned_worker_idx');
            $table->dropConstrainedForeignId('planned_preferred_worker_id');
            $table->dropColumn('planned_worker_slot');
        });
    }
};
