<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_booking_session_worker_assignments')) {
            $this->createV2Table();

            return;
        }

        // The dev branch created this table first with the legacy session-team
        // shape. Upgrade it in place and preserve any existing assignments.
        $missingAcceptedAt = ! Schema::hasColumn('cleaning_booking_session_worker_assignments', 'accepted_at');
        $missingReleasedAt = ! Schema::hasColumn('cleaning_booking_session_worker_assignments', 'released_at');
        $missingReleasedReason = ! Schema::hasColumn('cleaning_booking_session_worker_assignments', 'released_reason');

        Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table) use (
            $missingAcceptedAt,
            $missingReleasedAt,
            $missingReleasedReason,
        ): void {
            if ($missingAcceptedAt) {
                $table->timestamp('accepted_at')->nullable();
            }
            if ($missingReleasedAt) {
                $table->timestamp('released_at')->nullable();
            }
            if ($missingReleasedReason) {
                $table->text('released_reason')->nullable();
            }
        });

        if (! Schema::hasIndex('cleaning_booking_session_worker_assignments', 'cleaning_session_assignment_status_idx')) {
            Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table): void {
                $table->index(
                    ['cleaning_booking_session_id', 'status'],
                    'cleaning_session_assignment_status_idx'
                );
            });
        }

        if (! Schema::hasIndex('cleaning_booking_session_worker_assignments', 'cleaning_worker_session_status_idx')) {
            Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table): void {
                $table->index(
                    ['worker_id', 'status'],
                    'cleaning_worker_session_status_idx'
                );
            });
        }
    }

    private function createV2Table(): void
    {
        Schema::create('cleaning_booking_session_worker_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id')
                ->constrained('cleaning_booking_sessions')
                ->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('released_reason')->nullable();

            $table->timestamp('started_travel_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('last_latitude', 11, 8)->nullable();
            $table->decimal('last_longitude', 11, 8)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->timestamp('start_approved_at')->nullable();
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_finished_at')->nullable();
            $table->text('worker_completion_message')->nullable();

            $table->decimal('service_share_amount', 12, 2)->default(0);
            $table->decimal('travel_fee', 12, 2)->default(0);
            $table->decimal('admin_margin_amount', 12, 2)->default(0);
            $table->decimal('worker_amount', 12, 2)->default(0);
            $table->string('currency', 12)->nullable();
            $table->timestamps();

            $table->unique(
                ['cleaning_booking_session_id', 'worker_id'],
                'cleaning_session_worker_unique'
            );
            $table->index(
                ['cleaning_booking_session_id', 'status'],
                'cleaning_session_assignment_status_idx'
            );
            $table->index(
                ['worker_id', 'status'],
                'cleaning_worker_session_status_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_booking_session_worker_assignments')) {
            return;
        }

        foreach (['cleaning_session_assignment_status_idx', 'cleaning_worker_session_status_idx'] as $index) {
            if (Schema::hasIndex('cleaning_booking_session_worker_assignments', $index)) {
                Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        $columns = array_values(array_filter([
            'accepted_at',
            'released_at',
            'released_reason',
        ], static fn (string $column): bool => Schema::hasColumn('cleaning_booking_session_worker_assignments', $column)));

        if ($columns !== []) {
            Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
