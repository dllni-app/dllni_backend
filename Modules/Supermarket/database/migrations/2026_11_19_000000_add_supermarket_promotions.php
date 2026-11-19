<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sm_offers', function (Blueprint $table): void {
            $table->string('promotion_type')->default('product_discount');
            $table->unsignedInteger('buy_quantity')->nullable();
            $table->unsignedInteger('reward_quantity')->nullable();
            $table->string('reward_type')->nullable();
            $table->decimal('reward_discount_percent', 5, 2)->nullable();
            $table->boolean('is_repeatable')->default(false);
            $table->unsignedInteger('max_applications_per_order')->nullable();
            $table->unsignedBigInteger('rule_version')->default(1);
            $table->boolean('show_upcoming')->default(false);
            $table->index(['store_id', 'promotion_type', 'is_active']);
        });
        Schema::create('sm_offer_reward_products', function (Blueprint $table): void {
            $table->foreignId('offer_id')->constrained('sm_offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('sm_products')->cascadeOnDelete();
            $table->unique(['offer_id', 'product_id']);
        });
        Schema::table('sm_cart_items', fn (Blueprint $table) => $table->unsignedBigInteger('reward_offer_id')->nullable()->index());
        Schema::table('sm_carts', fn (Blueprint $table) => $table->boolean('promotion_pricing_required')->default(false));
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->decimal('original_items_subtotal', 12, 2)->nullable();
            $table->decimal('promotion_discount_amount', 12, 2)->default(0);
            $table->json('promotion_snapshot')->nullable();
        });
        Schema::table('sm_order_items', function (Blueprint $table): void {
            $table->decimal('original_unit_price', 10, 2)->nullable();
            $table->decimal('original_total_price', 12, 2)->nullable();
            $table->decimal('promotion_discount_amount', 12, 2)->default(0);
            $table->unsignedInteger('reward_quantity')->default(0);
            $table->json('promotion_snapshot')->nullable();
        });
        Schema::create('sm_order_promotion_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('sm_orders')->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('sm_offers')->nullOnDelete();
            $table->unsignedBigInteger('original_offer_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('rule_version');
            $table->unsignedInteger('application_count');
            $table->unsignedInteger('reward_quantity');
            $table->unsignedInteger('free_quantity');
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('net_eligible_revenue', 12, 2);
            $table->json('rule_snapshot');
            $table->string('status')->default('applied');
            $table->timestamp('applied_at');
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->unique(['order_id', 'original_offer_id']);
            $table->index(['store_id', 'status', 'applied_at']);
            $table->index(['original_offer_id', 'status', 'applied_at'], 'sm_promotion_offer_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_order_promotion_applications');
        Schema::table('sm_order_items', fn (Blueprint $table) => $table->dropColumn(['original_unit_price', 'original_total_price', 'promotion_discount_amount', 'reward_quantity', 'promotion_snapshot']));
        Schema::table('sm_orders', fn (Blueprint $table) => $table->dropColumn(['original_items_subtotal', 'promotion_discount_amount', 'promotion_snapshot']));
        Schema::table('sm_carts', fn (Blueprint $table) => $table->dropColumn('promotion_pricing_required'));
        Schema::table('sm_cart_items', function (Blueprint $table): void {
            $table->dropIndex(['reward_offer_id']);
            $table->dropColumn('reward_offer_id');
        });
        Schema::dropIfExists('sm_offer_reward_products');
        Schema::table('sm_offers', function (Blueprint $table): void {
            $table->dropIndex(['store_id', 'promotion_type', 'is_active']);
            $table->dropColumn(['promotion_type', 'buy_quantity', 'reward_quantity', 'reward_type', 'reward_discount_percent', 'is_repeatable', 'max_applications_per_order', 'rule_version', 'show_upcoming']);
        });
    }
};
