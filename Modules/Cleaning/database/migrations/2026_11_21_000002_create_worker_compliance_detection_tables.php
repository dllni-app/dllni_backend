<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_compliance_site_eligibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_id')->nullable()->constrained('cleaning_booking_sessions', 'id', 'wc_site_session_fk')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_assignment_id');
            $table->char('site_key', 64);
            $table->text('latitude_encrypted');
            $table->text('longitude_encrypted');
            $table->timestamp('site_verified_at');
            $table->timestamp('served_at')->nullable();
            $table->timestamp('eligible_until')->nullable()->index();
            $table->timestamps();

            $table->unique(['source_type', 'source_assignment_id'], 'worker_compliance_site_source_unique');
            $table->index(['worker_id', 'eligible_until'], 'worker_compliance_site_worker_expiry_idx');
            $table->index(['worker_id', 'site_key', 'eligible_until'], 'worker_compliance_site_worker_key_idx');
            $table->index(['cleaning_booking_id', 'worker_id'], 'worker_compliance_site_booking_worker_idx');
        });

        Schema::create('worker_compliance_review_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->char('site_key', 64);
            $table->foreignId('cleaning_booking_id')->nullable()->constrained('cleaning_bookings')->nullOnDelete();
            $table->foreignId('cleaning_booking_session_id')->nullable()->constrained('cleaning_booking_sessions', 'id', 'wc_case_session_fk')->nullOnDelete();
            $table->timestamp('observation_started_at');
            $table->timestamp('observation_ended_at');
            $table->unsignedSmallInteger('sample_count');
            $table->string('distance_band', 16);
            $table->string('accuracy_band', 16);
            $table->string('rule_version', 32);
            $table->string('reason_code', 48);
            $table->string('status', 32)->default('new');
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('public_reference')->unique();
            $table->char('dedupe_key', 64)->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'worker_compliance_cases_status_created_idx');
            $table->index(['worker_id', 'site_key', 'created_at'], 'worker_compliance_cases_worker_site_idx');
        });

        Schema::create('worker_compliance_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->nullable()->constrained('worker_compliance_review_cases')->nullOnDelete();
            $table->uuid('case_reference');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->string('corroborating_reference', 128)->nullable();
            $table->string('independent_evidence_reference', 128)->nullable();
            $table->timestamp('worker_response_opportunity_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['case_reference', 'created_at'], 'worker_compliance_events_case_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_compliance_review_events');
        Schema::dropIfExists('worker_compliance_review_cases');
        Schema::dropIfExists('worker_compliance_site_eligibilities');
    }
};
