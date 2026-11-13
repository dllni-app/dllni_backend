<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedSmallInteger('servings')->nullable();
            $table->json('ingredient_exclusions')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['servings', 'ingredient_exclusions']));
    }
};
