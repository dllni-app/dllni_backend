<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_supervisor_assignments', function (Blueprint $table): void {
            // Released rows remain as an audit trail; only slot 1 is active.
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->unique(['cleaning_booking_id', 'active_slot'], 'superv_one_active');
        });

        $active = DB::table('cleaning_supervisor_assignments')->whereNull('released_at')
            ->orderBy('cleaning_booking_id')->orderByDesc('assigned_at')->orderByDesc('id')->get();
        $seen = [];
        foreach ($active as $assignment) {
            if (isset($seen[$assignment->cleaning_booking_id])) {
                throw new RuntimeException('Multiple active supervisors exist for booking '.$assignment->cleaning_booking_id);
            }
            $seen[$assignment->cleaning_booking_id] = true;
            DB::table('cleaning_supervisor_assignments')->where('id', $assignment->id)->update(['active_slot' => 1]);
        }

        Schema::create('cleaning_supervisor_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('worker_assignment_id')->nullable()->constrained('cleaning_booking_worker_assignments')->nullOnDelete();
            $table->string('type', 32);
            $table->text('description');
            $table->string('status', 32)->default('open');
            $table->timestamps();
            $table->index(['cleaning_booking_id', 'status']);
        });

        Schema::create('cleaning_supervisor_replacements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('worker_assignment_id')->constrained('cleaning_booking_worker_assignments')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 32)->default('pending');
            $table->timestamps();
            $table->index(['cleaning_booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_supervisor_replacements');
        Schema::dropIfExists('cleaning_supervisor_incidents');
        Schema::table('cleaning_supervisor_assignments', function (Blueprint $table): void {
            $table->dropUnique('superv_one_active');
            $table->dropColumn('active_slot');
        });
    }
};
