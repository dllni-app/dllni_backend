<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_modifier_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('min_selections')->default(0);
            $table->unsignedInteger('max_selections')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active'], 'sm_mod_groups_store_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_modifier_groups');
    }
};
