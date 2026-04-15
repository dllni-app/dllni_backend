<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sm_smart_list_schedules', function (Blueprint $table): void {
            $table->json('week_days')->nullable()->after('frequency_type');
            $table->json('month_days')->nullable()->after('week_days');
            $table->json('periods')->nullable()->after('month_days');
        });
    }

    public function down(): void
    {
        Schema::table('sm_smart_list_schedules', function (Blueprint $table): void {
            $table->dropColumn(['week_days', 'month_days', 'periods']);
        });
    }
};