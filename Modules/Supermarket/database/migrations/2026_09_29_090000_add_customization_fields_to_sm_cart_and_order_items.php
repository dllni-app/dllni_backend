<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sm_cart_items', function (Blueprint $table): void {
            $table->json('modifier_ids')->nullable()->after('unit_price');
            $table->foreignId('substitute_product_id')->nullable()->after('modifier_ids')
                ->constrained('sm_products')->nullOnDelete();
            $table->text('note')->nullable()->after('substitute_product_id');
        });

        Schema::table('sm_order_items', function (Blueprint $table): void {
            $table->json('modifier_snapshot')->nullable()->after('product_name');
            $table->foreignId('substitute_product_id')->nullable()->after('modifier_snapshot')
                ->constrained('sm_products')->nullOnDelete();
            $table->text('note')->nullable()->after('substitute_product_id');
        });

        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->decimal('delivery_fee', 14, 2)->default(0)->after('service_fee');
        });
    }

    public function down(): void
    {
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->dropColumn('delivery_fee');
        });

        Schema::table('sm_order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('substitute_product_id');
            $table->dropColumn(['modifier_snapshot', 'note']);
        });
        Schema::table('sm_cart_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('substitute_product_id');
            $table->dropColumn(['modifier_ids', 'note']);
        });
    }
};
