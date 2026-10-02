<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->foreignId('commission_rule_id')
                ->nullable()
                ->after('service_fee')
                ->constrained('sm_commission_rules')
                ->nullOnDelete();
            $table->decimal('commission_base_amount', 14, 2)->nullable()->after('commission_rule_id');
            $table->decimal('commission_amount', 14, 2)->nullable()->after('commission_base_amount');
            $table->decimal('store_net_amount', 14, 2)->nullable()->after('commission_amount');
            $table->json('commission_snapshot')->nullable()->after('store_net_amount');

            $table->index(['store_id', 'commission_rule_id'], 'sm_orders_store_commission_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->dropIndex('sm_orders_store_commission_rule_idx');
            $table->dropConstrainedForeignId('commission_rule_id');
            $table->dropColumn([
                'commission_base_amount',
                'commission_amount',
                'store_net_amount',
                'commission_snapshot',
            ]);
        });
    }
};
