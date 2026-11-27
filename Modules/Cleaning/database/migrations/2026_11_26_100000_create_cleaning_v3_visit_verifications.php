<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_v3_visit_verifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('visit_id')->primary();
            $table->foreign('visit_id', 'cv3_verify_visit_fk')->references('id')
                ->on('cleaning_booking_sessions')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users');
            $table->char('crew_digest', 64);
            $table->string('code_hash');
            $table->text('code_ciphertext')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_v3_visit_verifications');
    }
};
