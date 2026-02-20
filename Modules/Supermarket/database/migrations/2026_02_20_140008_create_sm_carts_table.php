<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'store_id'], 'sm_cart_user_store_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_carts');
    }
};
