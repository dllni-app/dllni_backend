<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_quote_bookings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('quote_id');
            $table->foreign('quote_id', 'cqb_quote_fk')->references('id')->on('cleaning_booking_quotes')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings', 'id', 'cqb_booking_fk')->cascadeOnDelete();
            $table->unsignedInteger('appointment_sequence');
            $table->unique(['quote_id', 'appointment_sequence'], 'cqb_quote_sequence_unique');
            $table->unique('cleaning_booking_id', 'cqb_booking_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_quote_bookings');
    }
};
