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
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteTables();

            return;
        }

        if ($driver !== 'mysql') {
            throw new \RuntimeException("Unsupported database driver for Cleaning V2 execution foundation: {$driver}");
        }

        $this->createMySqlTables();
        $this->addMySqlChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_session_task_worker_segments');
        Schema::dropIfExists('cleaning_booking_session_supervisor_work_segments');
        Schema::dropIfExists('cleaning_booking_session_supervisor_attendances');
        Schema::dropIfExists('cleaning_booking_session_verifications');
        Schema::dropIfExists('cleaning_booking_session_task_workers');
        Schema::dropIfExists('cleaning_booking_session_tasks');
    }

    private function createMySqlTables(): void
    {
        Schema::create('cleaning_booking_session_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id');
            $table->string('task_key', 160);
            $table->string('source_kind', 24);
            $table->foreignId('cleaning_room_schedule_id')->nullable();
            $table->foreignId('cleaning_booking_room_period_id')->nullable();
            $table->unsignedSmallInteger('required_workers');
            $table->json('provenance_snapshot')->nullable();
            $table->string('status', 24)->default('unallocated');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('cleaning_booking_session_id', 'cbst_session_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->foreign('cleaning_room_schedule_id', 'cbst_schedule_fk')
                ->references('id')->on('cleaning_room_schedules')->restrictOnDelete();
            $table->foreign('cleaning_booking_room_period_id', 'cbst_period_fk')
                ->references('id')->on('cleaning_booking_room_periods')->restrictOnDelete();
            $table->unique(['cleaning_booking_session_id', 'task_key'], 'cbst_session_key_uq');
            $table->unique('cleaning_room_schedule_id', 'cbst_schedule_uq');
            $table->unique('cleaning_booking_room_period_id', 'cbst_period_uq');
        });

        Schema::create('cleaning_booking_session_task_workers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_task_id');
            $table->foreignId('cleaning_booking_session_worker_assignment_id');
            $table->foreignId('allocated_by_supervisor_assignment_id')->nullable();
            $table->foreignId('allocated_by_user_id')->nullable();
            $table->string('membership_source', 32);
            $table->timestamp('allocated_at');
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamps();

            $table->foreign('cleaning_booking_session_task_id', 'cbstw_task_fk')
                ->references('id')->on('cleaning_booking_session_tasks')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_worker_assignment_id', 'cbstw_worker_fk')
                ->references('id')->on('cleaning_booking_session_worker_assignments')->restrictOnDelete();
            $table->foreign('allocated_by_supervisor_assignment_id', 'cbstw_supervisor_fk')
                ->references('id')->on('cleaning_booking_session_supervisor_assignments')->restrictOnDelete();
            $table->foreign('allocated_by_user_id', 'cbstw_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['cleaning_booking_session_task_id', 'cleaning_booking_session_worker_assignment_id', 'active_slot'],
                'cbstw_active_uq'
            );
        });

        Schema::create('cleaning_booking_session_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id');
            $table->foreignId('cleaning_booking_session_supervisor_assignment_id');
            $table->foreignId('customer_user_id');
            $table->string('code_hash', 255)->nullable();
            $table->text('customer_code_ciphertext')->nullable();
            $table->string('status', 24);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamps();

            $table->foreign('cleaning_booking_session_id', 'cbsv_session_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_supervisor_assignment_id', 'cbsv_supervisor_fk')
                ->references('id')->on('cleaning_booking_session_supervisor_assignments')->restrictOnDelete();
            $table->foreign('customer_user_id', 'cbsv_customer_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(['cleaning_booking_session_id', 'active_slot'], 'cbsv_active_uq');
        });

        Schema::create('cleaning_booking_session_supervisor_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id');
            $table->foreignId('cleaning_booking_session_supervisor_assignment_id');
            $table->timestamp('started_travel_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('last_location_latitude', 10, 7)->nullable();
            $table->decimal('last_location_longitude', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->foreignId('last_actor_user_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->foreign('cleaning_booking_session_id', 'cbsa_session_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_supervisor_assignment_id', 'cbsa_supervisor_fk')
                ->references('id')->on('cleaning_booking_session_supervisor_assignments')->restrictOnDelete();
            $table->foreign('last_actor_user_id', 'cbsa_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique('cleaning_booking_session_supervisor_assignment_id', 'cbsa_assignment_uq');
        });

        Schema::create('cleaning_booking_session_supervisor_work_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id');
            $table->foreignId('cleaning_booking_session_supervisor_assignment_id');
            $table->foreignId('initiating_task_id');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->foreignId('ended_by_user_id')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamps();

            $table->foreign('cleaning_booking_session_id', 'cbssws_session_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_supervisor_assignment_id', 'cbssws_supervisor_fk')
                ->references('id')->on('cleaning_booking_session_supervisor_assignments')->restrictOnDelete();
            $table->foreign('initiating_task_id', 'cbssws_task_fk')
                ->references('id')->on('cleaning_booking_session_tasks')->restrictOnDelete();
            $table->foreign('ended_by_user_id', 'cbssws_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(['cleaning_booking_session_id', 'active_slot'], 'cbssws_active_uq');
        });

        Schema::create('cleaning_booking_session_task_worker_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_task_worker_id');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamps();

            $table->foreign('cleaning_booking_session_task_worker_id', 'cbstws_member_fk')
                ->references('id')->on('cleaning_booking_session_task_workers')->restrictOnDelete();
            $table->foreign('recorded_by_user_id', 'cbstws_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(['cleaning_booking_session_task_worker_id', 'active_slot'], 'cbstws_active_uq');
        });
    }

    private function addMySqlChecks(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE cleaning_booking_session_tasks
ADD CONSTRAINT cbs_task_source_ck CHECK (
    (source_kind = 'room_schedule' AND cleaning_room_schedule_id IS NOT NULL AND cleaning_booking_room_period_id IS NULL)
    OR (source_kind = 'room_period' AND cleaning_room_schedule_id IS NULL AND cleaning_booking_room_period_id IS NOT NULL)
    OR (source_kind = 'visit_scope' AND cleaning_room_schedule_id IS NULL AND cleaning_booking_room_period_id IS NULL)
)
SQL);
        DB::statement('ALTER TABLE cleaning_booking_session_task_workers ADD CONSTRAINT cbstw_active_ck CHECK (active_slot IS NULL OR active_slot = 1)');
        DB::statement('ALTER TABLE cleaning_booking_session_verifications ADD CONSTRAINT cbsv_active_ck CHECK (active_slot IS NULL OR active_slot = 1)');
        DB::statement('ALTER TABLE cleaning_booking_session_supervisor_work_segments ADD CONSTRAINT cbssws_active_ck CHECK (active_slot IS NULL OR active_slot = 1)');
        DB::statement('ALTER TABLE cleaning_booking_session_task_worker_segments ADD CONSTRAINT cbstws_active_ck CHECK (active_slot IS NULL OR active_slot = 1)');
    }

    private function createSqliteTables(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');
        $enabled = (int) DB::scalar('PRAGMA foreign_keys');
        if ($enabled !== 1) {
            throw new \RuntimeException('SQLite foreign keys must be enabled for Cleaning V2 execution foundation.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE cleaning_booking_session_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cleaning_booking_session_id INTEGER NOT NULL,
    task_key VARCHAR(160) NOT NULL,
    source_kind VARCHAR(24) NOT NULL,
    cleaning_room_schedule_id INTEGER NULL,
    cleaning_booking_room_period_id INTEGER NULL,
    required_workers INTEGER NOT NULL,
    provenance_snapshot TEXT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'unallocated',
    version INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT cbst_session_fk FOREIGN KEY (cleaning_booking_session_id) REFERENCES cleaning_booking_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT cbst_schedule_fk FOREIGN KEY (cleaning_room_schedule_id) REFERENCES cleaning_room_schedules(id) ON DELETE RESTRICT,
    CONSTRAINT cbst_period_fk FOREIGN KEY (cleaning_booking_room_period_id) REFERENCES cleaning_booking_room_periods(id) ON DELETE RESTRICT,
    CONSTRAINT cbst_session_key_uq UNIQUE (cleaning_booking_session_id, task_key),
    CONSTRAINT cbst_schedule_uq UNIQUE (cleaning_room_schedule_id),
    CONSTRAINT cbst_period_uq UNIQUE (cleaning_booking_room_period_id),
    CONSTRAINT cbs_task_source_ck CHECK (
        (source_kind = 'room_schedule' AND cleaning_room_schedule_id IS NOT NULL AND cleaning_booking_room_period_id IS NULL)
        OR (source_kind = 'room_period' AND cleaning_room_schedule_id IS NULL AND cleaning_booking_room_period_id IS NOT NULL)
        OR (source_kind = 'visit_scope' AND cleaning_room_schedule_id IS NULL AND cleaning_booking_room_period_id IS NULL)
    )
)
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE cleaning_booking_session_task_workers (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cleaning_booking_session_task_id INTEGER NOT NULL,
    cleaning_booking_session_worker_assignment_id INTEGER NOT NULL,
    allocated_by_supervisor_assignment_id INTEGER NULL,
    allocated_by_user_id INTEGER NULL,
    membership_source VARCHAR(32) NOT NULL,
    allocated_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    release_reason TEXT NULL,
    active_slot INTEGER NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT cbstw_task_fk FOREIGN KEY (cleaning_booking_session_task_id) REFERENCES cleaning_booking_session_tasks(id) ON DELETE RESTRICT,
    CONSTRAINT cbstw_worker_fk FOREIGN KEY (cleaning_booking_session_worker_assignment_id) REFERENCES cleaning_booking_session_worker_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT cbstw_supervisor_fk FOREIGN KEY (allocated_by_supervisor_assignment_id) REFERENCES cleaning_booking_session_supervisor_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT cbstw_actor_fk FOREIGN KEY (allocated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT cbstw_active_uq UNIQUE (cleaning_booking_session_task_id, cleaning_booking_session_worker_assignment_id, active_slot),
    CONSTRAINT cbstw_active_ck CHECK (active_slot IS NULL OR active_slot = 1)
)
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE cleaning_booking_session_verifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cleaning_booking_session_id INTEGER NOT NULL,
    cleaning_booking_session_supervisor_assignment_id INTEGER NOT NULL,
    customer_user_id INTEGER NOT NULL,
    code_hash VARCHAR(255) NULL,
    customer_code_ciphertext TEXT NULL,
    status VARCHAR(24) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    consumed_at DATETIME NULL,
    invalidated_at DATETIME NULL,
    active_slot INTEGER NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT cbsv_session_fk FOREIGN KEY (cleaning_booking_session_id) REFERENCES cleaning_booking_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT cbsv_supervisor_fk FOREIGN KEY (cleaning_booking_session_supervisor_assignment_id) REFERENCES cleaning_booking_session_supervisor_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT cbsv_customer_fk FOREIGN KEY (customer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT cbsv_active_uq UNIQUE (cleaning_booking_session_id, active_slot),
    CONSTRAINT cbsv_active_ck CHECK (active_slot IS NULL OR active_slot = 1)
)
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE cleaning_booking_session_supervisor_attendances (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cleaning_booking_session_id INTEGER NOT NULL,
    cleaning_booking_session_supervisor_assignment_id INTEGER NOT NULL,
    started_travel_at DATETIME NULL,
    arrived_at DATETIME NULL,
    last_location_latitude NUMERIC NULL,
    last_location_longitude NUMERIC NULL,
    location_updated_at DATETIME NULL,
    last_actor_user_id INTEGER NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT cbsa_session_fk FOREIGN KEY (cleaning_booking_session_id) REFERENCES cleaning_booking_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT cbsa_supervisor_fk FOREIGN KEY (cleaning_booking_session_supervisor_assignment_id) REFERENCES cleaning_booking_session_supervisor_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT cbsa_actor_fk FOREIGN KEY (last_actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT cbsa_assignment_uq UNIQUE (cleaning_booking_session_supervisor_assignment_id)
)
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE cleaning_booking_session_supervisor_work_segments (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cleaning_booking_session_id INTEGER NOT NULL,
    cleaning_booking_session_supervisor_assignment_id INTEGER NOT NULL,
    initiating_task_id INTEGER NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    end_reason VARCHAR(64) NULL,
    ended_by_user_id INTEGER NULL,
    active_slot INTEGER NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT cbssws_session_fk FOREIGN KEY (cleaning_booking_session_id) REFERENCES cleaning_booking_sessions(id) ON DELETE RESTRICT,
    CONSTRAINT cbssws_supervisor_fk FOREIGN KEY (cleaning_booking_session_supervisor_assignment_id) REFERENCES cleaning_booking_session_supervisor_assignments(id) ON DELETE RESTRICT,
    CONSTRAINT cbssws_task_fk FOREIGN KEY (initiating_task_id) REFERENCES cleaning_booking_session_tasks(id) ON DELETE RESTRICT,
    CONSTRAINT cbssws_actor_fk FOREIGN KEY (ended_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT cbssws_active_uq UNIQUE (cleaning_booking_session_id, active_slot),
    CONSTRAINT cbssws_active_ck CHECK (active_slot IS NULL OR active_slot = 1)
)
SQL);

        DB::statement(<<<'SQL'
CREATE TABLE cleaning_booking_session_task_worker_segments (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cleaning_booking_session_task_worker_id INTEGER NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    end_reason VARCHAR(64) NULL,
    recorded_by_user_id INTEGER NULL,
    active_slot INTEGER NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    CONSTRAINT cbstws_member_fk FOREIGN KEY (cleaning_booking_session_task_worker_id) REFERENCES cleaning_booking_session_task_workers(id) ON DELETE RESTRICT,
    CONSTRAINT cbstws_actor_fk FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT cbstws_active_uq UNIQUE (cleaning_booking_session_task_worker_id, active_slot),
    CONSTRAINT cbstws_active_ck CHECK (active_slot IS NULL OR active_slot = 1)
)
SQL);
    }
};
