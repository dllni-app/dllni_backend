<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->timestamp('queued_at', 3)->nullable()->after('attempts_count');
            $table->timestamp('job_started_at', 3)->nullable()->after('queued_at');
            $table->unsignedInteger('queue_wait_ms')->nullable()->after('job_started_at');
            $table->unsignedInteger('provider_execution_ms')->nullable()->after('queue_wait_ms');
            $table->unsignedInteger('job_execution_ms')->nullable()->after('provider_execution_ms');
            $table->timestamp('job_finished_at', 3)->nullable()->after('job_execution_ms');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'queued_at',
                'job_started_at',
                'queue_wait_ms',
                'provider_execution_ms',
                'job_execution_ms',
                'job_finished_at',
            ]);
        });
    }
};
