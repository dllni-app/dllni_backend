<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_assistant_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('sm_stores')->nullOnDelete();
            $table->string('input_mode');
            $table->text('query_text')->nullable();
            $table->string('voice_file_path')->nullable();
            $table->json('matched_product_ids')->nullable();
            $table->foreignId('matched_recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->index('user_id', 'sm_asst_user_idx');
            $table->index('store_id', 'sm_asst_store_idx');
            $table->index('created_at', 'sm_asst_created_idx');
            $table->index('matched_recipe_id', 'sm_asst_recipe_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_assistant_queries');
    }
};
