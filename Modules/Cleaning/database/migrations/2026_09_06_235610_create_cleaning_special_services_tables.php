<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_special_services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('image_url', 2048)->nullable();
            $table->string('image_path')->nullable();
            $table->string('pricing_unit', 32);
            $table->decimal('base_unit_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'pricing_unit'], 'clean_special_active_unit_idx');
        });

        Schema::create('cleaning_special_service_dirtiness_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_special_service_id')
                ->constrained(indexName: 'clean_special_dirtiness_service_fk')
                ->cascadeOnDelete();
            $table->string('dirtiness_level', 32);
            $table->decimal('price_multiplier', 8, 3)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['cleaning_special_service_id', 'dirtiness_level'], 'clean_special_dirtiness_unique');
        });

        Schema::create('cleaning_special_service_equipment', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cleaning_special_service_equipment_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_special_service_id')
                ->constrained(indexName: 'clean_special_equip_map_service_fk')
                ->cascadeOnDelete();
            // The catalog intentionally keeps its legacy singular table name.
            // Laravel's inferred plural name points at a non-existent table on
            // SQLite/MySQL, so keep the foreign target explicit.
            $table->foreignId('cleaning_special_service_equipment_id')
                ->constrained('cleaning_special_service_equipment', indexName: 'clean_special_equip_map_equipment_fk')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['cleaning_special_service_id', 'cleaning_special_service_equipment_id'], 'clean_special_equipment_unique');
        });

        Schema::create('cleaning_booking_special_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')
                ->constrained(indexName: 'clean_book_special_booking_fk')
                ->cascadeOnDelete();
            $table->foreignId('cleaning_special_service_id')
                ->constrained(indexName: 'clean_book_special_service_fk')
                ->restrictOnDelete();
            $table->string('service_name');
            $table->string('pricing_unit', 32);
            $table->string('dirtiness_level', 32);
            $table->decimal('quantity', 12, 3);
            $table->decimal('base_unit_price', 12, 2);
            $table->decimal('price_multiplier', 8, 3);
            $table->decimal('total_price', 12, 2);
            $table->json('equipment_snapshot')->nullable();
            $table->string('notes', 2000)->nullable();
            $table->timestamps();
            $table->unique(['cleaning_booking_id', 'cleaning_special_service_id'], 'clean_book_special_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_special_services');
        Schema::dropIfExists('cleaning_special_service_equipment_map');
        Schema::dropIfExists('cleaning_special_service_equipment');
        Schema::dropIfExists('cleaning_special_service_dirtiness_rules');
        Schema::dropIfExists('cleaning_special_services');
    }
};
