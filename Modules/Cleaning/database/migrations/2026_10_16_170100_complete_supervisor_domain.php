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
        // MySQL DDL cannot be rolled back atomically. These guards permit safe retry
        // after a failure halfway through adding a table or index.
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_bookings', 'supervised_state')) {
                $table->string('supervised_state', 40)->nullable();
            }
            if (! Schema::hasColumn('cleaning_bookings', 'supervised_base_minutes')) {
                $table->unsignedInteger('supervised_base_minutes')->nullable();
            }
            if (! Schema::hasColumn('cleaning_bookings', 'supervised_duration_minutes')) {
                $table->unsignedInteger('supervised_duration_minutes')->nullable();
            }
            if (! Schema::hasColumn('cleaning_bookings', 'supervised_scheduled_end_at')) {
                $table->dateTime('supervised_scheduled_end_at')->nullable();
            }
            if (! Schema::hasColumn('cleaning_bookings', 'supervised_original_end_at')) {
                $table->dateTime('supervised_original_end_at')->nullable();
            }
        });

        $active = DB::table('cleaning_supervisor_assignments')->whereNull('released_at')
            ->orderBy('cleaning_booking_id')->orderByDesc('assigned_at')->orderByDesc('id')->get();
        $seen = [];
        foreach ($active as $assignment) {
            if (isset($seen[$assignment->cleaning_booking_id])) {
                throw new RuntimeException('Multiple active supervisors exist for booking '.$assignment->cleaning_booking_id);
            }
            $seen[$assignment->cleaning_booking_id] = true;
        }
        if (! Schema::hasColumn('cleaning_supervisor_assignments', 'active_slot')) {
            Schema::table('cleaning_supervisor_assignments', function (Blueprint $table): void {
                $table->unsignedTinyInteger('active_slot')->nullable();
            });
        }
        if (! Schema::hasIndex('cleaning_supervisor_assignments', 'superv_one_active')) {
            Schema::table('cleaning_supervisor_assignments', function (Blueprint $table): void {
                $table->unique(['cleaning_booking_id', 'active_slot'], 'superv_one_active');
            });
        }
        foreach ($active as $assignment) {
            DB::table('cleaning_supervisor_assignments')->where('id', $assignment->id)->update(['active_slot' => 1]);
        }

        if (! Schema::hasTable('cleaning_supervisor_incidents')) {
            Schema::create('cleaning_supervisor_incidents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
                $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('worker_assignment_id')->nullable()->constrained('cleaning_booking_worker_assignments')->nullOnDelete();
                $table->string('type', 32);
                $table->text('description');
                $table->string('status', 32)->default('open');
                $table->timestamps();
                $table->index(['cleaning_booking_id', 'status'], 'cs_incident_booking_status_idx');
            });
        }
        if (! Schema::hasTable('cleaning_supervisor_replacements')) {
            Schema::create('cleaning_supervisor_replacements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
                $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('worker_assignment_id')->constrained('cleaning_booking_worker_assignments')->restrictOnDelete();
                $table->text('reason');
                $table->string('status', 32)->default('pending');
                $table->timestamps();
                $table->index(['cleaning_booking_id', 'status'], 'cs_replace_booking_status_idx');
            });
        }
        if (! Schema::hasIndex('cleaning_supervisor_replacements', 'cs_replace_booking_status_idx')) {
            Schema::table('cleaning_supervisor_replacements', function (Blueprint $table): void {
                $table->index(['cleaning_booking_id', 'status'], 'cs_replace_booking_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_supervisor_replacements');
        Schema::dropIfExists('cleaning_supervisor_incidents');
        Schema::table('cleaning_supervisor_assignments', function (Blueprint $table): void {
            $table->dropUnique('superv_one_active');
            $table->dropColumn('active_slot');
        });
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'supervised_state', 'supervised_base_minutes', 'supervised_duration_minutes',
                'supervised_scheduled_end_at', 'supervised_original_end_at',
            ]);
        });
    }
};
