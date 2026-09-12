<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cleaning_booking_sessions', 'customer_completed_at')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->timestamp('customer_completed_at')->nullable()->after('work_finished_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cleaning_booking_sessions', 'customer_completed_at')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->dropColumn('customer_completed_at');
            });
        }
    }
};
