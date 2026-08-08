<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_search_terms', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_searches_count')
                ->default(0)
                ->after('searches_count');
            $table->unsignedBigInteger('merchant_searches_count')
                ->default(0)
                ->after('product_searches_count');

            $table->index(
                ['section', 'product_searches_count'],
                'user_search_terms_section_product_count_index'
            );
            $table->index(
                ['section', 'merchant_searches_count'],
                'user_search_terms_section_merchant_count_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_search_terms', function (Blueprint $table): void {
            $table->dropIndex('user_search_terms_section_product_count_index');
            $table->dropIndex('user_search_terms_section_merchant_count_index');
            $table->dropColumn([
                'product_searches_count',
                'merchant_searches_count',
            ]);
        });
    }
};
