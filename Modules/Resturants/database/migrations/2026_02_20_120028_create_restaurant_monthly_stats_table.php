<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_monthly_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->year('stat_year');
            $table->unsignedTinyInteger('stat_month');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('average_order_value', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['restaurant_id', 'stat_year', 'stat_month'], 'rms_rest_year_month_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_monthly_stats');
    }
};
