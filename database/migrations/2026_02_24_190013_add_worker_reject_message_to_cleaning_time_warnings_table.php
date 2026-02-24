<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            $table->string('worker_reject_message', 500)->nullable()->after('worker_responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            $table->dropColumn('worker_reject_message');
        });
    }
};
