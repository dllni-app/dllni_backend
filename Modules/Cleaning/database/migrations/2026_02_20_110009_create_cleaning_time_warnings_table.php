<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_time_warnings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->string('booking_type');
            $table->string('customer_response')->nullable();
            $table->string('worker_response')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('customer_responded_at')->nullable();
            $table->timestamp('worker_responded_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'booking_type', 'sent_at'], 'ctw_booking_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_time_warnings');
    }
};
