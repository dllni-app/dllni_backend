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
        Schema::create('support_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_number')->unique();
            $table->string('kind', 32)->index();
            $table->string('priority', 32)->default('normal')->index();
            $table->nullableMorphs('booking');
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_role', 32)->nullable()->index();
            $table->string('category', 64)->nullable()->index();
            $table->text('description');
            $table->string('status', 32)->default('new')->index();
            $table->string('resolution', 64)->nullable();
            $table->text('resolution_note')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('worker_earnings_frozen')->default(false);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('context')->nullable();
            $table->string('client_request_id', 100)->nullable();
            $table->string('legacy_type', 32)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();

            $table->unique(['legacy_type', 'legacy_id']);
            $table->index(['reporter_id', 'booking_type', 'booking_id']);
            $table->index(['kind', 'status', 'created_at']);
        });

        Schema::create('support_case_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_role', 32);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('support_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->migrateLegacyDisputes();
        $this->migrateLegacySosAlerts();
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_events');
        Schema::dropIfExists('support_case_messages');
        Schema::dropIfExists('support_cases');
    }

    private function migrateLegacyDisputes(): void
    {
        if (! Schema::hasTable('disputes')) {
            return;
        }

        DB::table('disputes')->orderBy('id')->chunkById(200, function ($disputes): void {
            foreach ($disputes as $dispute) {
                $status = match ((string) $dispute->status) {
                    'under_review' => 'under_review',
                    'resolved' => 'resolved',
                    'closed', 'rejected' => 'closed',
                    default => 'new',
                };

                $caseId = DB::table('support_cases')->insertGetId([
                    'case_number' => filled($dispute->ticket_number ?? null)
                        ? (string) $dispute->ticket_number
                        : 'DSP-'.str_pad((string) $dispute->id, 8, '0', STR_PAD_LEFT),
                    'kind' => 'complaint',
                    'priority' => 'normal',
                    'booking_id' => $dispute->booking_id,
                    'booking_type' => $dispute->booking_type,
                    'reporter_id' => null,
                    'reporter_role' => 'customer',
                    'category' => $dispute->category,
                    'description' => (string) ($dispute->description ?? 'Legacy dispute'),
                    'status' => $status,
                    'resolution' => $this->mapLegacyResolution($dispute->resolution ?? null),
                    'worker_earnings_frozen' => (bool) ($dispute->worker_earnings_frozen ?? false),
                    'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? ($dispute->updated_at ?? now()) : null,
                    'legacy_type' => 'dispute',
                    'legacy_id' => $dispute->id,
                    'created_at' => $dispute->created_at ?? now(),
                    'updated_at' => $dispute->updated_at ?? now(),
                ]);

                if (Schema::hasTable('dispute_messages')) {
                    DB::table('dispute_messages')
                        ->where('dispute_id', $dispute->id)
                        ->orderBy('id')
                        ->get()
                        ->each(function ($message) use ($caseId): void {
                            DB::table('support_case_messages')->insert([
                                'support_case_id' => $caseId,
                                'sender_id' => $message->sender_id,
                                'sender_role' => $message->sender_type ?: 'customer',
                                'body' => (string) $message->body,
                                'created_at' => $message->created_at ?? now(),
                                'updated_at' => $message->updated_at ?? now(),
                            ]);
                        });
                }
            }
        });
    }

    private function migrateLegacySosAlerts(): void
    {
        if (! Schema::hasTable('sos_alerts')) {
            return;
        }

        DB::table('sos_alerts')->orderBy('id')->chunkById(200, function ($alerts): void {
            foreach ($alerts as $alert) {
                $status = match ((string) $alert->status) {
                    'acknowledged' => 'acknowledged',
                    'resolved' => 'resolved',
                    default => 'new',
                };

                DB::table('support_cases')->insert([
                    'case_number' => 'SOS-'.str_pad((string) $alert->id, 8, '0', STR_PAD_LEFT),
                    'kind' => 'emergency',
                    'priority' => 'critical',
                    'booking_id' => $alert->booking_id,
                    'booking_type' => $alert->booking_type,
                    'reporter_id' => $alert->user_id,
                    'reporter_role' => $this->legacyReporterRole((string) ($alert->source ?? '')),
                    'category' => $alert->emergency_type,
                    'description' => (string) ($alert->message ?? 'Legacy SOS alert'),
                    'status' => $status,
                    'latitude' => $alert->latitude,
                    'longitude' => $alert->longitude,
                    'acknowledged_by' => $alert->acknowledged_by,
                    'acknowledged_at' => $alert->acknowledged_at,
                    'resolved_by' => $alert->resolved_by,
                    'resolved_at' => $alert->resolved_at,
                    'resolution_note' => $alert->resolution_note,
                    'legacy_type' => 'sos_alert',
                    'legacy_id' => $alert->id,
                    'created_at' => $alert->created_at ?? now(),
                    'updated_at' => $alert->updated_at ?? now(),
                ]);
            }
        });
    }

    private function mapLegacyResolution(?string $resolution): ?string
    {
        return match ($resolution) {
            'worker_penalty' => 'worker_penalty',
            'full_refund', 'partial_refund' => 'refund',
            'dismissed' => 'dismissed',
            default => null,
        };
    }

    private function legacyReporterRole(string $source): string
    {
        return str_contains($source, 'worker') ? 'worker' : 'customer';
    }
};
