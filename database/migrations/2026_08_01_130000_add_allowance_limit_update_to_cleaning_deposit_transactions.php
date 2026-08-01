<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment', 'debt', 'commission', 'allowance_limit_update') NOT NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::table('cleaning_deposit_transactions')
            ->where('type', 'allowance_limit_update')
            ->update(['type' => 'debt']);

        DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment', 'debt', 'commission') NOT NULL");
    }
};
