<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_booking_price_adjustment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id');
            $table->foreignId('worker_id')
                ->constrained('workers')
                ->cascadeOnDelete();
            $table->decimal('old_total_price', 12, 2)->default(0);
            $table->decimal('proposed_total_price', 12, 2);
            $table->text('reason')->nullable();
            $table->string('status', 50)->default('pending');
            $table->decimal('admin_final_total_price', 12, 2)->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('cleaning_booking_id', 'cb_price_adj_booking_fk')
                ->references('id')
                ->on('cleaning_bookings')
                ->cascadeOnDelete();

            $table->index(['cleaning_booking_id', 'status'], 'cb_price_adj_booking_status_idx');
            $table->index(['worker_id', 'status'], 'cb_price_adj_worker_status_idx');
            $table->index('created_at', 'cb_price_adj_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_price_adjustment_requests');
    }
};
