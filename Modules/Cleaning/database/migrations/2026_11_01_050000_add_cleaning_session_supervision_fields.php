<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_booking_sessions')) {
            return;
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'supervision_mode')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->string('supervision_mode')->nullable();
            });
        }

        if (! Schema::hasColumn('cleaning_booking_sessions', 'supervision_initialized_at')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->timestamp('supervision_initialized_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_booking_sessions')) {
            return;
        }

        foreach (['supervision_mode', 'supervision_initialized_at'] as $column) {
            if (Schema::hasColumn('cleaning_booking_sessions', $column)) {
                Schema::table('cleaning_booking_sessions', static function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
