<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_extensions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->string('booking_type');
            $table->unsignedInteger('extra_minutes');
            $table->string('status');
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'booking_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extensions');
    }
};
