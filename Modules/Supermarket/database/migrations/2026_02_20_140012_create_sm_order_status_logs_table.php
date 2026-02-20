<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_order_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('sm_orders')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'created_at'], 'sm_ord_status_order_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_order_status_logs');
    }
};
