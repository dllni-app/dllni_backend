<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cleaning_worker_attendance_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('worker_assignment_id')->unique()->constrained('cleaning_booking_worker_assignments')->cascadeOnDelete();
            $table->string('code_hash');
            $table->text('worker_code_ciphertext');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['cleaning_booking_id', 'confirmed_at'], 'cw_att_booking_confirmed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_worker_attendance_proofs');
    }
};
