<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_material_units', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('symbol', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cleaning_material_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('cleaning_material_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('price_per_unit', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cleaning_materials', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('cleaning_material_type_id')->constrained()->restrictOnDelete();
            $table->decimal('stock_quantity', 12, 3)->default(0);
            $table->decimal('low_stock_threshold', 12, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'stock_quantity'], 'clean_mat_active_stock_idx');
        });

        Schema::create('cleaning_material_quantity_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_material_id')->constrained()->cascadeOnDelete();
            $table->string('room_type', 32)->nullable();
            $table->string('room_size', 32)->nullable();
            $table->string('cleaning_mode', 32)->nullable();
            $table->decimal('quantity_per_room', 12, 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['cleaning_material_id', 'is_active'], 'clean_mat_rule_active_idx');
        });

        Schema::create('cleaning_booking_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cleaning_material_id')->constrained()->restrictOnDelete();
            $table->foreignId('cleaning_material_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('cleaning_material_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('inventory_status', 24)->default('reserved');
            $table->timestamps();
            $table->unique(['cleaning_booking_id', 'cleaning_material_id'], 'clean_book_material_unique');
            $table->index(['cleaning_booking_id', 'inventory_status'], 'clean_book_material_status_idx');
        });

        Schema::create('cleaning_material_inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_material_id')->constrained()->restrictOnDelete();
            $table->foreignId('cleaning_booking_material_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type', 24);
            $table->decimal('quantity_delta', 12, 3);
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['cleaning_booking_material_id', 'movement_type'], 'clean_mat_move_booking_type_unique');
            $table->index(['cleaning_material_id', 'created_at'], 'clean_mat_move_material_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_material_inventory_movements');
        Schema::dropIfExists('cleaning_booking_materials');
        Schema::dropIfExists('cleaning_material_quantity_rules');
        Schema::dropIfExists('cleaning_materials');
        Schema::dropIfExists('cleaning_material_types');
        Schema::dropIfExists('cleaning_material_units');
    }
};
