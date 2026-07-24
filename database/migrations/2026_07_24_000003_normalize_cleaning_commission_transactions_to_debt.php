<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cleaning_deposit_transactions')
            ->whereIn('type', ['commission', 'admin_fee'])
            ->update(['type' => 'debt']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'settlement', 'refund', 'adjustment', 'debt') NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment', 'debt', 'commission') NOT NULL");
        }
    }
};
