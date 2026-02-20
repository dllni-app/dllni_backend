<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_trust_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->integer('score_delta');
            $table->timestamps();

            $table->index(['worker_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_trust_logs');
    }
};
