<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sm_products') || Schema::hasColumn('sm_products', 'master_product_id')) {
            return;
        }

        Schema::table('sm_products', function (Blueprint $table): void {
            $table->foreignId('master_product_id')
                ->nullable()
                ->after('category_id')
                ->constrained('master_products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Compatibility migration: the column is part of the canonical sm_products
        // schema already, so rolling this migration back must not remove it from
        // databases where it existed before this migration was introduced.
    }
};
