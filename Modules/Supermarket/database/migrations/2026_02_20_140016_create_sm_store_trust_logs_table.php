<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_store_trust_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->string('event_type');
            $table->integer('score_delta')->default(0);
            $table->unsignedInteger('score_after')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'created_at'], 'sm_trust_store_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_store_trust_logs');
    }
};
