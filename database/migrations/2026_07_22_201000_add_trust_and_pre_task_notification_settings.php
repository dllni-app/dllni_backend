<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_deposit_settings', 'trust_low_rating_threshold')) {
                $table->unsignedTinyInteger('trust_low_rating_threshold')->default(2)->after('trust_minimum_for_dispatch');
            }
            if (! Schema::hasColumn('cleaning_deposit_settings', 'trust_low_rating_penalty')) {
                $table->unsignedInteger('trust_low_rating_penalty')->default(5)->after('trust_low_rating_threshold');
            }
        });

        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_financial_settings', 'pre_task_notifications_enabled')) {
                $table->boolean('pre_task_notifications_enabled')->default(true)->after('cancellation_worker_fee_percentage');
            }
            if (! Schema::hasColumn('cleaning_financial_settings', 'pre_task_reminder_minutes')) {
                $table->unsignedInteger('pre_task_reminder_minutes')->default(60)->after('pre_task_notifications_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
            foreach (['trust_low_rating_threshold', 'trust_low_rating_penalty'] as $column) {
                if (Schema::hasColumn('cleaning_deposit_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            foreach (['pre_task_notifications_enabled', 'pre_task_reminder_minutes'] as $column) {
                if (Schema::hasColumn('cleaning_financial_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
