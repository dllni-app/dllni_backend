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
        $legacyDefault = 0.0;

        if (Schema::hasTable('cleaning_deposit_settings')
            && Schema::hasColumn('cleaning_deposit_settings', 'default_max_negative_balance')) {
            $legacyDefault = max(
                0.0,
                (float) (DB::table('cleaning_deposit_settings')->value('default_max_negative_balance') ?? 0),
            );
        }

        if (Schema::hasTable('cleaning_worker_deposits')) {
            DB::table('cleaning_worker_deposits')
                ->whereNull('max_negative_balance')
                ->update(['max_negative_balance' => $legacyDefault]);

            DB::table('workers')
                ->orderBy('id')
                ->eachById(function (object $worker) use ($legacyDefault): void {
                    if (DB::table('cleaning_worker_deposits')->where('worker_id', $worker->id)->exists()) {
                        return;
                    }

                    DB::table('cleaning_worker_deposits')->insert([
                        'worker_id' => $worker->id,
                        'current_balance' => 0,
                        'debt_balance' => 0,
                        'deposited_total' => 0,
                        'withdrawn_total' => 0,
                        'admin_revenue_withdrawn_total' => 0,
                        'minimum_required' => 0,
                        'max_negative_balance' => $legacyDefault,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
        }

        if (Schema::hasTable('cleaning_deposit_settings')) {
            Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('cleaning_deposit_settings', 'default_max_negative_balance')
                        ? 'default_max_negative_balance'
                        : null,
                    Schema::hasColumn('cleaning_deposit_settings', 'is_enabled')
                        ? 'is_enabled'
                        : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_deposit_settings')) {
            return;
        }

        Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_deposit_settings', 'default_max_negative_balance')) {
                $table->decimal('default_max_negative_balance', 12, 2)->default(0)->after('minimum_deposit_amount');
            }

            if (! Schema::hasColumn('cleaning_deposit_settings', 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->after('minimum_deposit_amount');
            }
        });
    }
};
