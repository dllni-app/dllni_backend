<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_time_warnings', 'additional_minutes')) {
                return;
            }
            $table->unsignedSmallInteger('additional_minutes')->nullable()->after('worker_responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            $table->dropColumn('additional_minutes');
        });
    }
};
