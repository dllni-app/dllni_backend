<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cleaning_supervisor_compensation_payables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id');
            $table->foreignId('supervisor_user_id');
            $table->unique('cleaning_booking_id', 'cscp_booking_unique');
            $table->foreign('cleaning_booking_id', 'cscp_booking_fk')
                ->references('id')->on('cleaning_bookings')->restrictOnDelete();
            $table->foreign('supervisor_user_id', 'cscp_supervisor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8);
            $table->string('status')->default('accrued');
            $table->timestamp('accrued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_supervisor_compensation_payables');
    }
};
