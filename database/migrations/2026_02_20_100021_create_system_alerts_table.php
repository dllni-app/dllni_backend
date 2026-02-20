<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->string('booking_type');
            $table->string('alert_type');
            $table->string('severity');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['booking_type', 'status', 'alert_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
