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
        if (! Schema::hasColumn('cleaning_deposit_transactions', 'cleaning_booking_id')) {
            return;
        }

        DB::table('cleaning_deposit_transactions')
            ->select(['id', 'worker_id', 'cleaning_booking_id', 'type'])
            ->whereNotNull('cleaning_booking_id')
            ->orderBy('id')
            ->chunkById(100, function ($transactions): void {
                foreach ($transactions as $transaction) {
                    if ((string) $transaction->type !== 'admin_fee') {
                        continue;
                    }

                    DB::table('cleaning_deposit_transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'reference' => 'automatic_admin_commission:'.hash(
                                'sha256',
                                ((int) $transaction->worker_id).':'.((int) $transaction->cleaning_booking_id),
                            ),
                            'notes' => null,
                        ]);
                }
            });

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $table->dropUnique('cleaning_deposit_tx_worker_type_booking_unique');
        });

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cleaning_booking_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('cleaning_deposit_transactions', 'cleaning_booking_id')) {
            return;
        }

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_id')
                ->nullable()
                ->after('worker_id')
                ->constrained('cleaning_bookings')
                ->nullOnDelete();

            $table->unique(
                ['worker_id', 'type', 'cleaning_booking_id'],
                'cleaning_deposit_tx_worker_type_booking_unique',
            );
        });
    }
};
