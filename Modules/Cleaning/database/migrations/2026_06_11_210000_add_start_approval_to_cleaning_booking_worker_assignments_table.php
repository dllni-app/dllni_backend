<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->timestamp('start_approved_at')->nullable()->after('accepted_at');
            $table->index('cleaning_booking_id', 'cbwa_booking_idx');
            $table->index('worker_id', 'cbwa_worker_idx');
            $table->index('status', 'cbwa_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->dropIndex('cbwa_booking_idx');
            $table->dropIndex('cbwa_worker_idx');
            $table->dropIndex('cbwa_status_idx');
            $table->dropColumn('start_approved_at');
        });
    }
};
