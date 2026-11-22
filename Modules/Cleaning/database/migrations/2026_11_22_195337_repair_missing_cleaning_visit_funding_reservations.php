<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'cleaning_visit_funding_reservations';

        if (Schema::hasTable($tableName)) {
            $requiredColumns = [
                'funding_source_id', 'cleaning_booking_session_id', 'active_visit_id',
                'reservation_key', 'quote_evidence_digest', 'reserved_amount',
                'currency', 'reservation_state', 'approved_by', 'approved_at', 'released_at',
            ];
            $uniqueIndexes = collect(Schema::getIndexes($tableName))
                ->filter(static fn (array $index): bool => $index['unique'])
                ->map(static fn (array $index): array => $index['columns']);

            if (! Schema::hasColumns($tableName, $requiredColumns)
                || ! $uniqueIndexes->contains(['active_visit_id'])
                || ! $uniqueIndexes->contains(['reservation_key'])) {
                throw new RuntimeException('Existing visit funding reservation schema requires manual reconciliation.');
            }

            return;
        }

        if (! Schema::hasTable('cleaning_platform_funding_sources')
            || ! Schema::hasTable('cleaning_booking_sessions')) {
            throw new RuntimeException('Visit funding reservations require the source and visit tables first.');
        }

        Schema::create($tableName, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('reservation_key')->unique('cvfr_reservation_key_uq');
            $table->foreignId('funding_source_id')
                ->constrained('cleaning_platform_funding_sources', 'id', 'cvfr_source_fk')
                ->restrictOnDelete();
            $table->foreignId('cleaning_booking_session_id')
                ->constrained('cleaning_booking_sessions', 'id', 'cvfr_visit_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('active_visit_id')->nullable();
            $table->foreign('active_visit_id', 'cvfr_active_visit_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->char('quote_evidence_digest', 64);
            $table->string('currency', 8);
            $table->decimal('reserved_amount', 16, 2);
            $table->decimal('worker_obligation_amount', 16, 2)->default(0);
            $table->decimal('supervisor_obligation_amount', 16, 2)->default(0);
            $table->json('liability_snapshot')->nullable();
            $table->string('reservation_state', 24)->default('pending');
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', 'id', 'cvfr_approved_by_fk')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 255)->nullable();
            $table->timestamps();

            $table->unique('active_visit_id', 'cvfr_one_active_visit_uq');
            $table->index(['funding_source_id', 'reservation_state'], 'cvfr_source_state_idx');
            $table->index(['cleaning_booking_session_id', 'released_at'], 'cvfr_visit_release_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Funding reservation schema repair cannot be rolled back destructively.');
    }
};
