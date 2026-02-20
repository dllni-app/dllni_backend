<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->date('stat_date');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('average_order_value', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['restaurant_id', 'stat_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_daily_stats');
    }
};
