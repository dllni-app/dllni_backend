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
        Schema::create('cleaning_v3_live_extension_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('cleaning_booking_session_id')->constrained('cleaning_booking_sessions', 'id', 'cv3le_visit_fk')->restrictOnDelete();
            $table->uuid('original_quote_id');
            $table->foreign('original_quote_id', 'cv3le_quote_fk')->references('id')->on('cleaning_booking_quotes')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users', 'id', 'cv3le_customer_fk')->restrictOnDelete();
            $table->string('command_key', 96);
            $table->unsignedInteger('previous_revision');
            $table->unsignedInteger('next_revision');
            $table->unsignedInteger('clock_revision');
            $table->string('status', 24);
            $table->json('payload');
            $table->char('signature', 64);
            $table->timestamp('expires_at');
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->char('customer_confirmation_mac', 64)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'cv3le_decider_fk')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->char('decision_mac', 64)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->uuid('applied_allocation_id')->nullable();
            $table->char('applied_mac', 64)->nullable();
            $table->unsignedInteger('applied_revision')->nullable();
            $table->timestamps();
            $table->unique(['cleaning_booking_session_id', 'command_key'], 'cv3le_command_uq');
            $table->unique(['cleaning_booking_session_id', 'applied_revision'], 'cv3le_applied_revision_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('cleaning_v3_live_extension_quotes')->exists()) {
            throw new RuntimeException('Cannot delete signed V3 live extension history.');
        }
        Schema::dropIfExists('cleaning_v3_live_extension_quotes');
    }
};
