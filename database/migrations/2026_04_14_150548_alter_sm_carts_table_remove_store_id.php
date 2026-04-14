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
        // Keep only the newest cart per user, delete all others
        DB::statement('
            DELETE FROM sm_carts
            WHERE id NOT IN (
                SELECT max_id FROM (
                    SELECT MAX(id) as max_id FROM sm_carts GROUP BY user_id
                ) as keepers
            )
        ');

        Schema::table('sm_carts', function (Blueprint $table): void {
            $table->dropForeign(['store_id']);
        });

        Schema::table('sm_carts', function (Blueprint $table): void {
            $table->dropUnique('sm_cart_user_store_uniq');
            $table->dropColumn('store_id');
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('sm_carts', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
        });

        Schema::table('sm_carts', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->constrained('sm_stores')->cascadeOnDelete();
            $table->unique(['user_id', 'store_id'], 'sm_cart_user_store_uniq');
        });
    }
};
