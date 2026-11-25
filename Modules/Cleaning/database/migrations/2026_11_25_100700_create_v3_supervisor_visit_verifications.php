<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_v3_supervisor_visit_verifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('visit_id')->primary();
            $table->foreign('visit_id', 'v3_supervisor_verification_visit_fk')->references('id')->on('cleaning_booking_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('supervisor_user_id');
            $table->string('roster_digest', 64);
            $table->string('code_hash', 255);
            $table->text('code_ciphertext')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_v3_supervisor_visit_verifications');
    }
};
