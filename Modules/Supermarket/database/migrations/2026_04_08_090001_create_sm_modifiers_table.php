<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_modifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained('sm_modifier_groups')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['modifier_group_id', 'is_available'], 'sm_modifiers_group_available_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_modifiers');
    }
};
