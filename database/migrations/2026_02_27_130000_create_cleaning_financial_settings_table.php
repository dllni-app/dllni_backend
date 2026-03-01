<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_financial_settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('default_commission_rate', 5, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->string('travel_markup_type')->default('fixed');
            $table->decimal('travel_markup_value', 10, 2)->default(0);
            $table->json('coverage_thresholds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_financial_settings');
    }
};
