<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_booking_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cleaning_service_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->timestamps();

            $table->index(['cleaning_booking_id', 'cleaning_service_id'], 'cbs_booking_service_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_service');
    }
};
