<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('delivery_orders', 'source_type')) {
                $table->string('source_type')->nullable()->after('created_by_user_id');
            }

            if (! Schema::hasColumn('delivery_orders', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            }
        });

        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->unique(['source_type', 'source_id'], 'delivery_orders_source_unique');
            $table->index(['created_by_user_id', 'status'], 'delivery_orders_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->dropUnique('delivery_orders_source_unique');
            $table->dropIndex('delivery_orders_user_status_idx');

            if (Schema::hasColumn('delivery_orders', 'source_id')) {
                $table->dropColumn('source_id');
            }

            if (Schema::hasColumn('delivery_orders', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};
