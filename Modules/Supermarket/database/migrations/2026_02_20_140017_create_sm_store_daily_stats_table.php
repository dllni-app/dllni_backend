<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_store_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('orders_revenue', 14, 2)->default(0);
            $table->unsignedInteger('unique_customers')->default(0);
            $table->unsignedInteger('new_customers')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'date'], 'sm_store_daily_store_date_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_store_daily_stats');
    }
};
