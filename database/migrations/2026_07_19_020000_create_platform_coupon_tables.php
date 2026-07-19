<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar');
            $table->text('description_en')->nullable();
            $table->string('section', 20);
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 12, 2);
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->string('audience_type', 20)->default('all_users');
            $table->unsignedInteger('total_usage_limit')->nullable();
            $table->unsignedInteger('per_user_usage_limit')->nullable()->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();

            $table->index(['section', 'is_active', 'starts_at', 'expires_at'], 'platform_coupons_availability_idx');
            $table->index(['audience_type', 'is_active'], 'platform_coupons_audience_idx');
        });

        Schema::create('platform_coupon_user', function (Blueprint $table): void {
            $table->foreignId('platform_coupon_id')->constrained('platform_coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['platform_coupon_id', 'user_id'], 'platform_coupon_user_unique');
        });

        Schema::create('platform_coupon_constraints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_coupon_id')->constrained('platform_coupons')->cascadeOnDelete();
            $table->string('constraint_type', 40);
            $table->string('constraint_value', 100);
            $table->timestamps();

            $table->unique(
                ['platform_coupon_id', 'constraint_type', 'constraint_value'],
                'platform_coupon_constraints_unique'
            );
        });

        Schema::create('platform_coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_coupon_id')->constrained('platform_coupons')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('section', 20);
            $table->string('order_type');
            $table->unsignedBigInteger('order_id');
            $table->string('coupon_code', 50);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->timestamp('redeemed_at')->useCurrent();
            $table->timestamps();

            $table->unique(
                ['platform_coupon_id', 'order_type', 'order_id'],
                'platform_coupon_redemption_order_unique'
            );
            $table->index(['platform_coupon_id', 'user_id'], 'platform_coupon_redemption_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_coupon_redemptions');
        Schema::dropIfExists('platform_coupon_constraints');
        Schema::dropIfExists('platform_coupon_user');
        Schema::dropIfExists('platform_coupons');
    }
};
