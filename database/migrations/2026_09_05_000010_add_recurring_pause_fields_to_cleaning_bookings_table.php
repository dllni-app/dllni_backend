<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->timestamp('recurring_paused_at')->nullable()->after('customer_confirmed_at');
            $table->text('recurring_pause_reason')->nullable()->after('recurring_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn(['recurring_paused_at', 'recurring_pause_reason']);
        });
    }
};
