<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_order_dispute_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispute_id')->constrained('sm_order_disputes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['dispute_id', 'created_at'], 'sm_disp_msg_disp_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_order_dispute_messages');
    }
};
