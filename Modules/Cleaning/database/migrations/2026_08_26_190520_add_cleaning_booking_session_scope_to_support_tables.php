<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addSessionReference('booking_security_codes', 'bsc_cleaning_session_idx');
        $this->addSessionReference('cleaning_time_warnings', 'ctw_cleaning_session_idx');
        $this->addSessionReference('sos_alerts', 'sos_cleaning_session_idx');
        $this->addSessionReference('disputes', 'disputes_cleaning_session_idx');
    }

    public function down(): void
    {
        $this->dropSessionReference('disputes', 'disputes_cleaning_session_idx');
        $this->dropSessionReference('sos_alerts', 'sos_cleaning_session_idx');
        $this->dropSessionReference('cleaning_time_warnings', 'ctw_cleaning_session_idx');
        $this->dropSessionReference('booking_security_codes', 'bsc_cleaning_session_idx');
    }

    private function addSessionReference(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'cleaning_booking_session_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->unsignedBigInteger('cleaning_booking_session_id')->nullable();
            $table->index('cleaning_booking_session_id', $indexName);
        });
    }

    private function dropSessionReference(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'cleaning_booking_session_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
            $table->dropColumn('cleaning_booking_session_id');
        });
    }
};
