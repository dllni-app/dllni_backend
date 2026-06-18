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
        Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
            $table->decimal('default_max_negative_balance', 12, 2)->default(0)->after('minimum_deposit_amount');
            $table->unsignedSmallInteger('trust_reject_after_accept_penalty')->default(10)->after('is_enabled');
            $table->unsignedSmallInteger('trust_minimum_for_dispatch')->default(0)->after('trust_reject_after_accept_penalty');
        });

        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            $table->decimal('minimum_required', 12, 2)->nullable()->after('withdrawn_total');
            $table->decimal('max_negative_balance', 12, 2)->nullable()->after('minimum_required');
        });

        $settings = DB::table('cleaning_deposit_settings')->first();
        if ($settings !== null) {
            DB::table('cleaning_worker_deposits')->update([
                'minimum_required' => $settings->minimum_deposit_amount,
                'max_negative_balance' => $settings->default_max_negative_balance ?? 0,
            ]);
        }

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_id')->nullable()->after('worker_id')->constrained('cleaning_bookings')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->after('cleaning_booking_id')->constrained('users')->nullOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee') NOT NULL");
        } else {
            Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
                $table->string('type', 32)->change();
            });
        }

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $table->unique(['worker_id', 'type', 'cleaning_booking_id'], 'cleaning_deposit_tx_worker_type_booking_unique');
        });

        Schema::table('worker_trust_logs', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_id')->nullable()->after('worker_id')->constrained('cleaning_bookings')->nullOnDelete();
            $table->unsignedSmallInteger('score_before')->nullable()->after('score_delta');
            $table->unsignedSmallInteger('score_after')->nullable()->after('score_before');
        });
    }

    public function down(): void
    {
        Schema::table('worker_trust_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cleaning_booking_id');
            $table->dropColumn(['score_before', 'score_after']);
        });

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $table->dropUnique('cleaning_deposit_tx_worker_type_booking_unique');
            $table->dropConstrainedForeignId('cleaning_booking_id');
            $table->dropConstrainedForeignId('created_by_admin_id');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal') NOT NULL");
        }

        Schema::table('cleaning_worker_deposits', function (Blueprint $table): void {
            $table->dropColumn(['minimum_required', 'max_negative_balance']);
        });

        Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'default_max_negative_balance',
                'trust_reject_after_accept_penalty',
                'trust_minimum_for_dispatch',
            ]);
        });
    }
};
