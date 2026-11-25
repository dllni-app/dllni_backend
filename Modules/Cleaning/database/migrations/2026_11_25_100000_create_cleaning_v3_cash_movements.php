<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_v3_cash_movements', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id')->constrained('cleaning_booking_sessions', 'id', 'cv3_cash_visit_fk')->restrictOnDelete();
            $table->uuid('quote_id');
            $table->foreign('quote_id', 'cv3_cash_quote_fk')->references('id')->on('cleaning_booking_quotes')->restrictOnDelete();
            $table->string('reference', 128)->unique('cv3_cash_ref_uq');
            $table->string('collection_reference', 128)->nullable();
            $table->foreign('collection_reference', 'cv3_cash_collection_fk')->references('reference')->on('cleaning_v3_cash_movements')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('beneficiary_key', 96);
            $table->string('status', 24);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('SYP');
            $table->string('evidence', 500);
            $table->char('calculation_digest', 64);
            $table->json('calculation');
            $table->foreignId('recorded_by')->constrained('users', 'id', 'cv3_cash_recorder_fk')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users', 'id', 'cv3_cash_verifier_fk')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['cleaning_booking_session_id', 'kind', 'status'], 'cv3_cash_visit_kind_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('cleaning_v3_cash_movements')->exists()) {
            throw new RuntimeException('Cannot delete V3 cash movement audit history.');
        }
        Schema::dropIfExists('cleaning_v3_cash_movements');
    }
};
