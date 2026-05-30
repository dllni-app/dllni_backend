<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_driver_trust_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained('delivery_drivers')->cascadeOnDelete();
            $table->string('reason');
            $table->integer('score_delta');
            $table->unsignedInteger('score_after');
            $table->foreignId('related_dispute_id')->nullable()->constrained('disputes')->nullOnDelete();
            $table->timestamps();

            $table->index(['driver_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_driver_trust_logs');
    }
};
