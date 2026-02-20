<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_order_dispute_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('restaurant_order_dispute_id');
            $table->foreign('restaurant_order_dispute_id', 'rodm_dispute_id_fk')
                ->references('id')
                ->on('restaurant_order_disputes')
                ->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_order_dispute_messages');
    }
};
