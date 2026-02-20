<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_security_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->string('booking_type');
            $table->string('code');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['booking_id', 'booking_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_security_codes');
    }
};
