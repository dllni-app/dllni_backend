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

    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_platform_funding_sources');
    }
};
