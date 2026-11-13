<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->string('offer_type')->default('product_discount');
            $table->unsignedInteger('buy_quantity')->nullable();
            $table->unsignedInteger('reward_quantity')->nullable();
            $table->string('reward_type')->nullable();
            $table->decimal('reward_discount_percent', 5, 2)->nullable();
            $table->boolean('is_repeatable')->default(false);
            $table->unsignedInteger('max_applications_per_order')->nullable();
            $table->unsignedBigInteger('rule_version')->default(1);
            $table->index(['restaurant_id', 'offer_type', 'is_active'], 'offers_promotion_lookup');
        });

        Schema::create('restaurant_offer_reward_products', function (Blueprint $table): void {
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['offer_id', 'product_id'], 'restaurant_offer_reward_unique');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            // Retain the intent even when the rule is deleted; a claimed reward
            // must not silently become a normally priced item.
            $table->unsignedBigInteger('reward_offer_id')->nullable()->index();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('original_items_subtotal', 12, 2)->nullable();
            $table->decimal('promotion_discount_amount', 12, 2)->default(0);
            $table->json('promotion_snapshot')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('original_unit_price', 10, 2)->nullable();
            $table->decimal('original_total_price', 12, 2)->nullable();
            $table->decimal('promotion_discount_amount', 12, 2)->default(0);
            $table->unsignedInteger('reward_quantity')->default(0);
            $table->json('promotion_snapshot')->nullable();
        });

        Schema::create('restaurant_order_promotion_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->unsignedBigInteger('original_offer_id');
            $table->unsignedBigInteger('restaurant_id');
            $table->string('mechanism');
            $table->unsignedBigInteger('rule_version');
            $table->unsignedInteger('application_count');
            $table->unsignedInteger('reward_quantity');
            $table->unsignedInteger('free_quantity');
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('net_eligible_revenue', 12, 2);
            $table->string('currency', 8);
            $table->json('rule_snapshot');
            $table->string('status')->default('applied');
            $table->timestamp('applied_at');
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->unique(['order_id', 'original_offer_id'], 'restaurant_order_offer_unique');
            $table->index(['restaurant_id', 'status', 'applied_at'], 'restaurant_promotion_ledger_lookup');
            $table->index(['original_offer_id', 'status', 'applied_at'], 'restaurant_promotion_offer_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_order_promotion_applications');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn([
            'original_unit_price', 'original_total_price', 'promotion_discount_amount', 'reward_quantity', 'promotion_snapshot',
        ]));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn([
            'original_items_subtotal', 'promotion_discount_amount', 'promotion_snapshot',
        ]));
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropIndex(['reward_offer_id']);
            $table->dropColumn('reward_offer_id');
        });
        Schema::dropIfExists('restaurant_offer_reward_products');
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropIndex('offers_promotion_lookup');
            $table->dropColumn([
                'offer_type', 'buy_quantity', 'reward_quantity', 'reward_type', 'reward_discount_percent',
                'is_repeatable', 'max_applications_per_order', 'rule_version',
            ]);
        });
    }
};