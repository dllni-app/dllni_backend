<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_booking_sessions')) {
            $this->createV2Table();

            return;
        }

        // dev already introduced a smaller session table on 2026-08-26. After
        // merging V2, upgrade that table in place instead of trying to create it
        // again. This also makes the migration safe for the production database
        // that has already executed the August migration.
        $this->addV2Columns();

        if (! Schema::hasIndex('cleaning_booking_sessions', 'cleaning_booking_session_coverage_date_idx')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->index(['coverage_status', 'scheduled_date'], 'cleaning_booking_session_coverage_date_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_booking_sessions')) {
            return;
        }

        if (Schema::hasIndex('cleaning_booking_sessions', 'cleaning_booking_session_coverage_date_idx')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->dropIndex('cleaning_booking_session_coverage_date_idx');
            });
        }

        $columns = array_values(array_filter([
            'session_type',
            'calculation_mode',
            'required_workers',
            'coverage_status',
            'addons_total',
            'materials_total',
            'special_services_total',
            'pricing_snapshot',
            'version',
            'skipped_at',
            'skip_source',
            'skip_reason',
        ], static fn (string $column): bool => Schema::hasColumn('cleaning_booking_sessions', $column)));

        if ($columns !== []) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function addV2Columns(): void
    {
        if (! Schema::hasColumn('cleaning_booking_sessions', 'session_type')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->string('session_type')->nullable();
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'calculation_mode')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->string('calculation_mode')->nullable();
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'required_workers')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->unsignedSmallInteger('required_workers')->default(1);
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'coverage_status')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->string('coverage_status')->default('searching');
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'addons_total')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->decimal('addons_total', 12, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'materials_total')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->decimal('materials_total', 12, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'special_services_total')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->decimal('special_services_total', 12, 2)->default(0);
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'pricing_snapshot')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->json('pricing_snapshot')->nullable();
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'version')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'skipped_at')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->timestamp('skipped_at')->nullable();
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'skip_source')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->string('skip_source')->nullable();
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'skip_reason')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->text('skip_reason')->nullable();
            });
        }
    }

    private function createV2Table(): void
    {
        Schema::create('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('session_type')->nullable();
            $table->string('calculation_mode')->nullable();
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->decimal('duration_hours', 8, 2)->default(0);
            $table->unsignedSmallInteger('required_workers')->default(1);
            $table->string('coverage_status')->default('searching');
            $table->string('status')->default('scheduled');

            $table->decimal('base_price', 12, 2)->default(0);
            $table->decimal('addons_total', 12, 2)->default(0);
            $table->decimal('materials_total', 12, 2)->default(0);
            $table->decimal('special_services_total', 12, 2)->default(0);
            $table->decimal('travel_fee', 12, 2)->default(0);
            $table->decimal('travel_distance_km', 10, 3)->nullable();
            $table->decimal('admin_margin_amount', 12, 2)->default(0);
            $table->decimal('extension_fee_total', 12, 2)->default(0);
            $table->decimal('cancellation_fee', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->boolean('is_pricing_final')->default(false);
            $table->json('pricing_snapshot')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->timestamp('started_travel_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_finished_at')->nullable();

            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_source')->nullable();
            $table->text('skip_reason')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('cancelled_by_role')->nullable();
            $table->timestamps();

            $table->unique(['cleaning_booking_id', 'sequence'], 'cleaning_booking_session_sequence_unique');
            $table->unique(['cleaning_booking_id', 'scheduled_date', 'scheduled_time'], 'cleaning_booking_session_slot_unique');
            $table->index(['scheduled_date', 'status'], 'cleaning_booking_session_date_status_idx');
            $table->index(['cleaning_booking_id', 'status'], 'cleaning_booking_session_booking_status_idx');
            $table->index(['coverage_status', 'scheduled_date'], 'cleaning_booking_session_coverage_date_idx');
        });
    }
};
