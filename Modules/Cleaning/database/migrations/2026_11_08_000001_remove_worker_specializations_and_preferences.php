<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove worker-specific service skills, equipment permissions, and
     * work-type preferences. Services and physical equipment remain available.
     * All data in the removed columns and tables is intentionally discarded.
     */
    public function up(): void
    {
        Schema::dropIfExists('cleaning_worker_equipment_authorizations');
        Schema::dropIfExists('cleaning_worker_special_service_skills');

        if (Schema::hasTable('cleaning_special_services') && Schema::hasColumn('cleaning_special_services', 'gender_constraint')) {
            Schema::table('cleaning_special_services', function (Blueprint $table): void {
                $table->dropColumn('gender_constraint');
            });
        }

        if (Schema::hasTable('workers')) {
            $columns = array_filter(
                ['preferred_work_type', 'preferred_work_types'],
                static fn (string $column): bool => Schema::hasColumn('workers', $column),
            );
            if ($columns !== []) {
                Schema::table('workers', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn(array_values($columns));
                });
            }
        }
    }

    /** Recreates empty legacy structures only; deleted specialization records cannot be recovered. */
    public function down(): void
    {
        if (! Schema::hasColumn('workers', 'preferred_work_type')) {
            Schema::table('workers', function (Blueprint $table): void {
                $table->enum('preferred_work_type', ['cleaning', 'events', 'both'])->default('both');
            });
        }
        if (! Schema::hasColumn('workers', 'preferred_work_types')) {
            Schema::table('workers', function (Blueprint $table): void {
                $table->json('preferred_work_types')->nullable();
            });
        }
        if (! Schema::hasColumn('cleaning_special_services', 'gender_constraint')) {
            Schema::table('cleaning_special_services', function (Blueprint $table): void {
                $table->string('gender_constraint', 16)->nullable();
            });
        }

        Schema::create('cleaning_worker_special_service_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('cleaning_special_service_id')
                ->constrained('cleaning_special_services', indexName: 'clean_worker_special_skill_service_fk')
                ->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['worker_id', 'cleaning_special_service_id'], 'clean_worker_special_skill_unique');
        });

        Schema::create('cleaning_worker_equipment_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('cleaning_special_service_equipment_id')
                ->constrained('cleaning_special_service_equipment', indexName: 'clean_worker_equipment_auth_equipment_fk')
                ->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['worker_id', 'cleaning_special_service_equipment_id'], 'clean_worker_equipment_auth_unique');
        });
    }
};
