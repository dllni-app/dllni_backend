<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_addon_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->timestamps();

            $table->index(['cleaning_booking_id', 'service_addon_id'], 'ba_booking_addon_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_addons');
    }
};
