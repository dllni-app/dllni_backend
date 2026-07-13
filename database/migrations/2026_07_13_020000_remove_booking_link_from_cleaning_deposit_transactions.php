<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->anonymizeAutomaticAdministrationDebtReferences();
        $this->removeBookingLink();
        $this->normalizeTransactionTypes();
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'withdrawal', 'admin_fee', 'settlement', 'refund', 'adjustment', 'debt') NOT NULL");
        }

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

    private function anonymizeAutomaticAdministrationDebtReferences(): void
    {
        $hasBookingColumn = Schema::hasColumn('cleaning_deposit_transactions', 'cleaning_booking_id');
        $columns = ['id', 'worker_id', 'type'];

        if ($hasBookingColumn) {
            $columns[] = 'cleaning_booking_id';
        }

        DB::table('cleaning_deposit_transactions')
            ->select($columns)
            ->where('type', 'admin_fee')
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use ($hasBookingColumn): void {
                foreach ($transactions as $transaction) {
                    $sourceId = $hasBookingColumn && $transaction->cleaning_booking_id !== null
                        ? 'booking:'.((int) $transaction->cleaning_booking_id)
                        : 'legacy-transaction:'.((int) $transaction->id);

                    DB::table('cleaning_deposit_transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.hash(
                                'sha256',
                                ((int) $transaction->worker_id).':'.$sourceId,
                            ),
                            'notes' => null,
                        ]);
                }
            });
    }

    private function removeBookingLink(): void
    {
        if (! Schema::hasColumn('cleaning_deposit_transactions', 'cleaning_booking_id')) {
            return;
        }

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $table->dropUnique('cleaning_deposit_tx_worker_type_booking_unique');
        });

        Schema::table('cleaning_deposit_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cleaning_booking_id');
        });
    }

    private function normalizeTransactionTypes(): void
    {
        DB::table('cleaning_deposit_transactions')
            ->where('type', 'admin_fee')
            ->update(['type' => 'debt']);

        DB::table('cleaning_deposit_transactions')
            ->where('type', 'withdrawal')
            ->update(['type' => 'refund']);

        DB::table('cleaning_deposit_transactions')
            ->where('type', 'adjustment')
            ->where('amount', '>=', 0)
            ->update(['type' => 'deposit']);

        DB::table('cleaning_deposit_transactions')
            ->where('type', 'adjustment')
            ->where('amount', '<', 0)
            ->update([
                'type' => 'refund',
                'amount' => DB::raw('ABS(amount)'),
            ]);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE cleaning_deposit_transactions MODIFY COLUMN type ENUM('deposit', 'debt', 'settlement', 'refund') NOT NULL");
        }
    }
};
