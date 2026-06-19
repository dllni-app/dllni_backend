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
            $table->text('worker_completion_message')->nullable()->after('work_finished_at');
            $table->text('customer_completion_rejection_message')->nullable()->after('worker_completion_message');
            $table->timestamp('completion_rejected_at')->nullable()->after('customer_completion_rejection_message');
        });

        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            $table->text('customer_message')->nullable()->after('customer_response');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            $table->dropColumn('customer_message');
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'worker_completion_message',
                'customer_completion_rejection_message',
                'completion_rejected_at',
            ]);
        });
    }
};
