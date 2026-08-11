<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cleaning_deposit_transactions', 'debt_settled_amount')) {
            Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
                $table->decimal('debt_settled_amount', 12, 2)
                    ->default(0)
                    ->after('amount');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment', 'debt') NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('cleaning_deposit_transactions')
                ->where('type', 'debt')
                ->update(['type' => 'adjustment']);

            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment') NOT NULL");
        }

        if (Schema::hasColumn('cleaning_deposit_transactions', 'debt_settled_amount')) {
            Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
                $table->dropColumn('debt_settled_amount');
            });
        }
    }
};
