<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_v3_catalog_versions', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('version_key')->unique('cv3_version_key_uq');
            $table->string('title', 180);
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'cv3_created_by_fk')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users', 'id', 'cv3_published_by_fk')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->char('published_digest', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'id'], 'cv3_status_idx');
        });

        Schema::create('cleaning_v3_catalog_items', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_version_id')->constrained('cleaning_v3_catalog_versions', 'id', 'cv3_item_version_fk')->cascadeOnDelete();
            $table->string('item_key', 100);
            // Existing catalog records are referenced by source_type/source_id.
            // V2 tables remain canonical; never copy or rewrite their prices.
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('label_ar', 180);
            $table->string('label_en', 180)->nullable();
            $table->string('measurement_mode', 32);
            $table->json('input_rules')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['catalog_version_id', 'item_key'], 'cv3_item_key_uq');
            $table->index(['source_type', 'source_id'], 'cv3_item_source_idx');
        });

        Schema::create('cleaning_v3_catalog_size_presets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('cleaning_v3_catalog_items', 'id', 'cv3_size_item_fk')->cascadeOnDelete();
            $table->string('preset_key', 60);
            $table->string('label_ar', 150);
            $table->string('label_en', 150)->nullable();
            $table->decimal('minimum_value', 12, 2);
            $table->decimal('maximum_value', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['catalog_item_id', 'preset_key'], 'cv3_size_key_uq');
        });
    }

    public function down(): void
    {
        // Never delete versions or potential published audit history on rollback.
        // Development QA-only down is intentionally explicit; production requires
        // a planned, approved rollback rather than destructive schema deletion.
        if (Schema::hasTable('cleaning_v3_catalog_versions')
            && Illuminate\Support\Facades\DB::table('cleaning_v3_catalog_versions')->exists()) {
            throw new RuntimeException('Cannot roll back a catalog containing draft or published versions.');
        }
        Schema::dropIfExists('cleaning_v3_catalog_size_presets');
        Schema::dropIfExists('cleaning_v3_catalog_items');
        Schema::dropIfExists('cleaning_v3_catalog_versions');
    }
};
