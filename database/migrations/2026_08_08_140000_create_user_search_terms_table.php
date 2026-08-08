<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_search_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('section', 20);
            $table->string('query');
            $table->string('normalized_query');
            $table->unsignedBigInteger('searches_count')->default(0);
            $table->timestamp('last_searched_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['section', 'normalized_query'],
                'user_search_terms_section_normalized_unique'
            );
            $table->index(
                ['section', 'searches_count'],
                'user_search_terms_section_count_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_search_terms');
    }
};
