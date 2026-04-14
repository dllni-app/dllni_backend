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
            DELETE FROM carts
            WHERE id NOT IN (
                SELECT max_id FROM (
                    SELECT MAX(id) as max_id FROM carts GROUP BY user_id
                ) as keepers
            )
        ');

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropForeign(['restaurant_id']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'restaurant_id']);
            $table->dropColumn('restaurant_id');
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('restaurant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'restaurant_id']);
        });
    }
};
