<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cleaning_booking_sessions')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                if (! Schema::hasColumn('cleaning_booking_sessions', 'supervision_mode')) {
                    $table->string('supervision_mode', 20)->nullable()->after('required_workers');
                }
                if (! Schema::hasColumn('cleaning_booking_sessions', 'supervision_initialized_at')) {
                    $table->timestamp('supervision_initialized_at')->nullable()->after('supervision_mode');
                }
            });
        }

        if (! Schema::hasTable('cleaning_booking_session_supervisor_assignments')) {
            Schema::create('cleaning_booking_session_supervisor_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cleaning_booking_session_id')
                    ->constrained('cleaning_booking_sessions', 'id', 'cbssa_session_fk')
                    ->cascadeOnDelete();
                $table->foreignId('supervisor_user_id')
                    ->constrained('users', 'id', 'cbssa_supervisor_fk')
                    ->restrictOnDelete();
                $table->foreignId('assigned_by_user_id')
                    ->constrained('users', 'id', 'cbssa_actor_fk')
                    ->restrictOnDelete();
                $table->timestamp('assigned_at');
                $table->timestamp('released_at')->nullable();
                $table->text('reason')->nullable();
                $table->decimal('compensation_hourly_rate', 12, 2);
                $table->unsignedTinyInteger('active_slot')->nullable();
                $table->timestamps();

                $table->unique(
                    ['cleaning_booking_session_id', 'active_slot'],
                    'cbssa_one_active'
                );
                $table->index(
                    ['supervisor_user_id', 'released_at'],
                    'cbssa_supervisor_release_idx'
                );
            });
        }

        if (Schema::hasTable('cleaning_financial_settings')
            && ! Schema::hasColumn('cleaning_financial_settings', 'supervisor_compensation_hourly_rate')) {
            Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
                $table->decimal('supervisor_compensation_hourly_rate', 12, 2)
                    ->default(0)
                    ->after('supervisor_hourly_rate');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_session_supervisor_assignments');

        if (Schema::hasTable('cleaning_booking_sessions')) {
            $columns = array_values(array_filter([
                'supervision_initialized_at',
                'supervision_mode',
            ], static fn (string $column): bool => Schema::hasColumn('cleaning_booking_sessions', $column)));

            if ($columns !== []) {
                Schema::table('cleaning_booking_sessions', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }

        if (Schema::hasTable('cleaning_financial_settings')
            && Schema::hasColumn('cleaning_financial_settings', 'supervisor_compensation_hourly_rate')) {
            Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
                $table->dropColumn('supervisor_compensation_hourly_rate');
            });
        }
    }
};
