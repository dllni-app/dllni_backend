<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('polygon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['worker_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_zones');
    }
};
