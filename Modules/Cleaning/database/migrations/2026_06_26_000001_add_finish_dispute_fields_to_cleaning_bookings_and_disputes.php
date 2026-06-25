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
            if (! Schema::hasColumn('cleaning_bookings', 'disputed_at')) {
                $table->timestamp('disputed_at')->nullable()->after('cancelled_at');
            }

            if (! Schema::hasColumn('cleaning_bookings', 'timer_stopped_at')) {
                $table->timestamp('timer_stopped_at')->nullable()->after('disputed_at');
            }
        });

        Schema::table('disputes', function (Blueprint $table): void {
            if (! Schema::hasColumn('disputes', 'reason_type')) {
                $table->string('reason_type')->nullable()->after('category')->index();
            }

            if (! Schema::hasColumn('disputes', 'reason_label')) {
                $table->string('reason_label')->nullable()->after('reason_type');
            }

            if (! Schema::hasColumn('disputes', 'reason_note')) {
                $table->text('reason_note')->nullable()->after('reason_label');
            }

            if (! Schema::hasColumn('disputes', 'opened_by_worker_id')) {
                $table->foreignId('opened_by_worker_id')->nullable()->after('worker_earnings_frozen')->constrained('workers')->nullOnDelete();
            }

            if (! Schema::hasColumn('disputes', 'opened_by_user_id')) {
                $table->foreignId('opened_by_user_id')->nullable()->after('opened_by_worker_id')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('disputes', 'opened_at')) {
                $table->timestamp('opened_at')->nullable()->after('opened_by_user_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table): void {
            foreach (['opened_by_worker_id', 'opened_by_user_id'] as $column) {
                if (Schema::hasColumn('disputes', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['reason_type', 'reason_label', 'reason_note', 'opened_at'] as $column) {
                if (Schema::hasColumn('disputes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            foreach (['disputed_at', 'timer_stopped_at'] as $column) {
                if (Schema::hasColumn('cleaning_bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
