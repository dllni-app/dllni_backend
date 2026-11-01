<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Room descriptions remain for service pricing and the customer task list.
        // Only the retired worker ownership and worker-room allocation data go.
        Schema::table('cleaning_booking_rooms', function (Blueprint $table): void {
            $table->dropForeign(['assigned_worker_id']);
            $table->dropForeign(['planned_preferred_worker_id']);
            $table->dropIndex('cbr_booking_worker_idx');
            $table->dropIndex('cbr_booking_planned_slot_idx');
            $table->dropIndex('cbr_booking_planned_worker_idx');
            $table->dropColumn([
                'assigned_worker_id',
                'planned_preferred_worker_id',
                'planned_worker_slot',
                'assignment_source',
            ]);
        });

        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->dropColumn(['room_count', 'rooms_weight']);
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_rooms', function (Blueprint $table): void {
            $table->foreignId('assigned_worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->foreignId('planned_preferred_worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->unsignedInteger('planned_worker_slot')->nullable();
            $table->string('assignment_source')->nullable();
            $table->index(['cleaning_booking_id', 'assigned_worker_id'], 'cbr_booking_worker_idx');
            $table->index(['cleaning_booking_id', 'planned_worker_slot'], 'cbr_booking_planned_slot_idx');
            $table->index(['cleaning_booking_id', 'planned_preferred_worker_id'], 'cbr_booking_planned_worker_idx');
        });

        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->unsignedInteger('room_count')->default(0);
            $table->decimal('rooms_weight', 10, 2)->default(0);
        });
    }
};
