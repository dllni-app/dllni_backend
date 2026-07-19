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
        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_worker_deposits', 'debt_balance')) {
                $table->decimal('debt_balance', 12, 2)->default(0)->after('current_balance');
            }
        });

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_deposit_transactions', 'debt_balance_before')) {
                $table->decimal('debt_balance_before', 12, 2)->default(0)->after('balance_after');
            }

            if (! Schema::hasColumn('cleaning_deposit_transactions', 'debt_balance_after')) {
                $table->decimal('debt_balance_after', 12, 2)->default(0)->after('debt_balance_before');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment', 'debt', 'commission') NOT NULL");
        }

        DB::table('cleaning_worker_deposits')
            ->where('current_balance', '<', 0)
            ->orderBy('id')
            ->eachById(function (object $row): void {
                $legacyNegativeBalance = abs((float) $row->current_balance);

                DB::table('cleaning_worker_deposits')
                    ->where('id', $row->id)
                    ->update([
                        'current_balance' => 0,
                        'debt_balance' => max((float) ($row->debt_balance ?? 0), $legacyNegativeBalance),
                    ]);
            });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('cleaning_deposit_transactions')
                ->where('type', 'commission')
                ->update(['type' => 'admin_fee']);

            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment', 'debt') NOT NULL");
        }

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('cleaning_deposit_transactions', 'debt_balance_before') ? 'debt_balance_before' : null,
                Schema::hasColumn('cleaning_deposit_transactions', 'debt_balance_after') ? 'debt_balance_after' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_worker_deposits', 'debt_balance')) {
                $table->dropColumn('debt_balance');
            }
        });
    }
};
