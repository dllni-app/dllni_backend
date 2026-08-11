<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_security_codes', function (Blueprint $table): void {
            $table->unsignedBigInteger('worker_id')->nullable()->after('booking_type');
            $table->index(['booking_id', 'booking_type', 'worker_id'], 'bsc_booking_worker_idx');
        });
    }

    public function down(): void
    {
        Schema::table('booking_security_codes', function (Blueprint $table): void {
            $table->dropIndex('bsc_booking_worker_idx');
            $table->dropColumn('worker_id');
        });
    }
};
