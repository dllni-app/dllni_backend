<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('sm_coupons')->nullOnDelete();
            $table->foreignId('cancellation_policy_id')->nullable()->constrained('cancellation_policies')->nullOnDelete();
            $table->string('order_number')->unique();
            $table->string('status');
            $table->string('pickup_mode');
            $table->timestamp('pickup_scheduled_for')->nullable();
            $table->timestamp('ready_for_pickup_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('customer_pickup_confirmed_at')->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('service_fee', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('cancellation_fee_amount', 14, 2)->nullable();
            $table->json('cancellation_policy_snapshot')->nullable();
            $table->text('special_instructions')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status'], 'sm_ord_cust_status_idx');
            $table->index(['store_id', 'status'], 'sm_ord_store_status_idx');
            $table->index('pickup_scheduled_for', 'sm_ord_pickup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_orders');
    }
};
