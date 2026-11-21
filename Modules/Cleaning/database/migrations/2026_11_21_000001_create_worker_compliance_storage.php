<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_compliance_states', function (Blueprint $table): void {
            $table->foreignId('worker_id')->primary()->constrained('workers')->cascadeOnDelete();
            $table->boolean('is_enrolled')->default(false);
            $table->char('installation_hash', 64)->nullable();
            $table->char('access_token_hash', 64)->nullable();
            $table->string('platform', 16)->nullable();
            $table->string('build', 64)->nullable();
            $table->uuid('epoch')->nullable()->unique();
            $table->string('notice_version', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_contact_at')->nullable()->index();
            $table->timestamp('last_usable_fix_at')->nullable();
            $table->string('last_reported_permission_state', 32)->nullable();
            $table->boolean('last_reported_location_services_enabled')->nullable();
            $table->unsignedInteger('late_sample_count')->default(0);
            $table->timestamps();
        });

        Schema::create('worker_compliance_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->uuid('epoch');
            $table->uuid('client_id');
            $table->timestamp('captured_at')->index();
            $table->timestamp('received_at');
            $table->text('latitude_encrypted');
            $table->text('longitude_encrypted');
            $table->decimal('accuracy_m', 9, 2);
            $table->string('quality', 24);
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['worker_id', 'epoch', 'client_id'], 'worker_compliance_samples_idempotency_unique');
            $table->index(['worker_id', 'captured_at'], 'worker_compliance_samples_worker_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_compliance_samples');
        Schema::dropIfExists('worker_compliance_states');
    }
};
