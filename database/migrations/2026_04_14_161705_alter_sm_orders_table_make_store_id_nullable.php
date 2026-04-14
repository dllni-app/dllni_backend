<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_id')->nullable()->change();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
        });

        Schema::enableForeignKeyConstraints();
    }
};
