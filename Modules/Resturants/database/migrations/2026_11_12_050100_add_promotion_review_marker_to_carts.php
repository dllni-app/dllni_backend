<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', fn (Blueprint $table) => $table->boolean('promotion_pricing_required')->default(false));
    }

    public function down(): void
    {
        Schema::table('carts', fn (Blueprint $table) => $table->dropColumn('promotion_pricing_required'));
    }
};
