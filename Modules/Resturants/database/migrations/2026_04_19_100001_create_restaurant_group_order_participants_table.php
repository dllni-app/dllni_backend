<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_group_order_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_order_id')->constrained('restaurant_group_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('joined');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['group_order_id', 'user_id']);
            $table->index(['group_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_group_order_participants');
    }
};
