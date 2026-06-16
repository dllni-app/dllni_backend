<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_assignment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('delivery_orders')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('delivery_drivers')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no');
            $table->string('status');
            $table->decimal('distance_to_pickup_km', 10, 3)->nullable();
            $table->timestamp('offered_at');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'driver_id', 'attempt_no'], 'daa_order_driver_attempt_uq');
            $table->index(['order_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignment_attempts');
    }
};
