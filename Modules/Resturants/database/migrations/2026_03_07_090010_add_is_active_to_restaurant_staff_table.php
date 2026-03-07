<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_staff', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('restaurant_role_id');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_staff', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
