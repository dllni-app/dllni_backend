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
        if (Schema::hasTable('cleaning_v3_visit_dispatches')
            || Schema::hasTable('cleaning_v3_dispatch_notice_intents')) {
            throw new RuntimeException('Existing V3 dispatch tracking tables require explicit reconciliation.');
        }

        Schema::create('cleaning_v3_visit_dispatches', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id')
                ->unique('cv3_dispatch_one_visit_uq')
                ->constrained('cleaning_booking_sessions', 'id', 'cv3_dispatch_visit_fk')
                ->restrictOnDelete();
            $table->timestamp('first_search_started_at');
            $table->timestamp('deadline_at');
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('first_fully_covered_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['deadline_at', 'escalated_at'], 'cv3_dispatch_due_idx');
        });

        Schema::create('cleaning_v3_dispatch_notice_intents', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_v3_visit_dispatch_id')
                ->constrained('cleaning_v3_visit_dispatches', 'id', 'cv3_notice_dispatch_fk')
                ->restrictOnDelete();
            // These are durable intents, not evidence that a notification
            // was delivered. Later queue/notification adapters consume them.
            $table->string('recipient_kind', 16);
            $table->string('event_key', 32)->default('six_hour_incomplete');
            $table->timestamp('queued_at');
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['cleaning_v3_visit_dispatch_id', 'recipient_kind', 'event_key'],
                'cv3_notice_once_per_actor_uq',
            );
            $table->index(['delivered_at', 'queued_at'], 'cv3_notice_outbox_idx');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('cleaning_v3_visit_dispatches')
                && DB::table('cleaning_v3_visit_dispatches')->exists())
            || (Schema::hasTable('cleaning_v3_dispatch_notice_intents')
                && DB::table('cleaning_v3_dispatch_notice_intents')->exists())) {
            throw new RuntimeException('Refusing to delete V3 dispatch or notice audit history.');
        }

        Schema::dropIfExists('cleaning_v3_dispatch_notice_intents');
        Schema::dropIfExists('cleaning_v3_visit_dispatches');
    }
};
