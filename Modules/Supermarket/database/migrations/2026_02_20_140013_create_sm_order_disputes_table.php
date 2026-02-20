<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_order_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('sm_orders')->cascadeOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ticket_number')->unique();
            $table->string('status');
            $table->string('reason')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index('order_id', 'sm_ord_disp_order_idx');
            $table->index('status', 'sm_ord_disp_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_order_disputes');
    }
};
