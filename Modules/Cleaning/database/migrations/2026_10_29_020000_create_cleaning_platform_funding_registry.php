<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry only. No admin/client can mark a credit cleared through this migration.
 * Independent clearing verification and locked reservation writes are separate
 * prerequisites; these tables MUST NOT be interpreted as an available wallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_platform_funding_sources', static function (Blueprint $table): void {
            $table->id();
            $table->string('provider_key', 64);
            $table->string('external_credit_id', 128);
            $table->string('owner_scope', 32)->default('cleaning_platform');
            $table->string('currency', 8);
            $table->decimal('gross_amount', 16, 2);
            $table->string('verification_state', 24)->default('unverified');
            $table->char('provider_evidence_digest', 64)->nullable();
            $table->timestamp('provider_verified_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_key', 'external_credit_id'], 'cpfs_provider_credit_uq');
            $table->index(['verification_state', 'reversed_at'], 'cpfs_verification_idx');
        });

        Schema::create('cleaning_visit_funding_reservations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_platform_funding_source_id');
            $table->foreignId('cleaning_booking_session_id');
            $table->string('currency', 8);
            $table->decimal('reserved_amount', 16, 2);
            $table->char('quote_evidence_digest', 64);
            $table->decimal('worker_obligations_amount', 16, 2);
            $table->decimal('supervisor_obligation_amount', 16, 2);
            $table->foreignId('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 255)->nullable();
            // Future transactional reservation writer must lock the source row,
            // check ALL active reservations, and set this slot atomically.
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamps();

            $table->foreign('cleaning_platform_funding_source_id', 'cvfr_source_fk')
                ->references('id')->on('cleaning_platform_funding_sources')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_id', 'cvfr_visit_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->foreign('approved_by_user_id', 'cvfr_approver_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(
                ['cleaning_platform_funding_source_id', 'cleaning_booking_session_id', 'active_slot'],
                'cvfr_source_visit_active_uq',
            );
            $table->index(['cleaning_booking_session_id', 'released_at'], 'cvfr_visit_release_idx');
            $table->index(['cleaning_platform_funding_source_id', 'released_at'], 'cvfr_source_release_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_visit_funding_reservations');
        Schema::dropIfExists('cleaning_platform_funding_sources');
    }
};
