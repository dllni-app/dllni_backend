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

        if (! Schema::hasTable('cleaning_worker_deposits')) {
            return;
        }

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

        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            $table->decimal('max_negative_balance', 12, 2)->default(0)->nullable(false)->change();
        });

        // Legacy global columns are deliberately retained as inert compatibility fields.
        // The dashboard, models, services, validation, and eligibility logic no longer read
        // or write them. Keeping the columns allows rolling deployments and older fixtures
        // to coexist safely while every worker receives an explicit individual limit.
        if (Schema::hasTable('cleaning_deposit_settings')) {
            if (Schema::hasColumn('cleaning_deposit_settings', 'is_enabled')) {
                DB::table('cleaning_deposit_settings')->update(['is_enabled' => true]);
            }

            if (Schema::hasColumn('cleaning_deposit_settings', 'default_max_negative_balance')) {
                DB::table('cleaning_deposit_settings')->update(['default_max_negative_balance' => 0]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_worker_deposits')) {
            return;
        }

        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            $table->decimal('max_negative_balance', 12, 2)->nullable()->default(null)->change();
        });
    }
};
