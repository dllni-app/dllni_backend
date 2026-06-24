<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicateCartItems();

        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->string('checkout_batch_number')->nullable()->after('cancellation_policy_id')->index('sm_orders_checkout_batch_idx');
            $table->unsignedInteger('checkout_orders_count')->default(1)->after('checkout_batch_number');
        });
    }

    public function down(): void
    {
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->dropIndex('sm_orders_checkout_batch_idx');
            $table->dropColumn(['checkout_batch_number', 'checkout_orders_count']);
        });
    }

    private function mergeDuplicateCartItems(): void
    {
        $duplicateGroups = DB::table('sm_cart_items')
            ->select([
                'cart_id',
                'product_id',
                DB::raw('MIN(id) as keep_id'),
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('MAX(updated_at) as latest_updated_at'),
            ])
            ->groupBy('cart_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            DB::table('sm_cart_items')
                ->where('id', (int) $group->keep_id)
                ->update([
                    'quantity' => (int) $group->total_quantity,
                    'updated_at' => $group->latest_updated_at,
                ]);

            DB::table('sm_cart_items')
                ->where('cart_id', (int) $group->cart_id)
                ->where('product_id', (int) $group->product_id)
                ->where('id', '!=', (int) $group->keep_id)
                ->delete();
        }
    }
};
