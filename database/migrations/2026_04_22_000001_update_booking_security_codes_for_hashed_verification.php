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
            $table->string('code_hash', 64)->nullable()->after('code');
            $table->unsignedSmallInteger('attempts')->default(0)->after('code_hash');
            $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            $table->timestamp('consumed_at')->nullable()->after('last_attempt_at');
            $table->index(['booking_id', 'booking_type', 'expires_at'], 'bsc_booking_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::table('booking_security_codes', function (Blueprint $table): void {
            $table->dropIndex('bsc_booking_expires_idx');
            $table->dropColumn(['code_hash', 'attempts', 'last_attempt_at', 'consumed_at']);
        });
    }
};
