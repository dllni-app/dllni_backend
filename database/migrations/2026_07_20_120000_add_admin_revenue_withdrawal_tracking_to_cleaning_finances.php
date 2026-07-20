<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_worker_deposits', 'admin_revenue_withdrawn_total')) {
                $table->decimal('admin_revenue_withdrawn_total', 12, 2)->default(0)->after('withdrawn_total');
            }
        });

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_deposit_transactions', 'admin_revenue_withdrawn_amount')) {
                $table->decimal('admin_revenue_withdrawn_amount', 12, 2)->default(0)->after('debt_settled_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_deposit_transactions', 'admin_revenue_withdrawn_amount')) {
                $table->dropColumn('admin_revenue_withdrawn_amount');
            }
        });

        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_worker_deposits', 'admin_revenue_withdrawn_total')) {
                $table->dropColumn('admin_revenue_withdrawn_total');
            }
        });
    }
};
