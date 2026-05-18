<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_booking_worker_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('rejected_at');
            $table->timestamps();

            $table->unique(['cleaning_booking_id', 'worker_id'], 'cleaning_booking_worker_rejections_unique');
            $table->index(['worker_id', 'rejected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_worker_rejections');
    }
};
