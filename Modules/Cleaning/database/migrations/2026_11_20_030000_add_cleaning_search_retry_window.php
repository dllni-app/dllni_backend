<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_bookings')) {
            return;
        }

        Schema::table('cleaning_bookings', static function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_bookings', 'search_started_at')) {
                $table->timestamp('search_started_at')->nullable();
            }
            if (! Schema::hasColumn('cleaning_bookings', 'search_retry_count')) {
                $table->unsignedInteger('search_retry_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_bookings')) {
            return;
        }

        Schema::table('cleaning_bookings', static function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_bookings', 'search_started_at')) {
                $table->dropColumn('search_started_at');
            }
            if (Schema::hasColumn('cleaning_bookings', 'search_retry_count')) {
                $table->dropColumn('search_retry_count');
            }
        });
    }
};
