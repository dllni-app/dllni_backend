<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'cleaning_booking_session_financial_penalties';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createTable();

            return;
        }

        // MySQL may leave the table behind when a later ALTER TABLE command
        // fails. Reconcile that partially-created table instead of trying to
        // create it again or dropping data that may already exist.
        $this->ensureIndexes();
        $this->ensureForeignKeys();
    }

    private function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id');
            $table->foreignId('cleaning_booking_session_id');
            $table->foreignId('worker_id')->nullable();
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('financial_transaction_id')->nullable();
            $table->string('reference_key', 160);
            $table->string('penalized_role', 20);
            $table->string('financial_source', 30);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('active');
            $table->text('reason_snapshot')->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->unique('reference_key', 'cleaning_session_penalty_reference_unique');
            $table->index(
                ['cleaning_booking_session_id', 'penalized_role', 'status'],
                'cleaning_session_penalty_session_role_idx',
            );
            $table->index(
                ['cleaning_booking_id', 'status'],
                'cleaning_session_penalty_booking_status_idx',
            );
            $table->index(['worker_id', 'status'], 'cleaning_session_penalty_worker_status_idx');
            $table->index(['customer_id', 'status'], 'cleaning_session_penalty_customer_status_idx');

            $table->foreign('cleaning_booking_id', 'cleaning_session_penalty_booking_fk')
                ->references('id')
                ->on('cleaning_bookings')
                ->cascadeOnDelete();
            $table->foreign('cleaning_booking_session_id', 'cleaning_session_penalty_session_fk')
                ->references('id')
                ->on('cleaning_booking_sessions')
                ->cascadeOnDelete();
            $table->foreign('worker_id', 'cleaning_session_penalty_worker_fk')
                ->references('id')
                ->on('workers')
                ->nullOnDelete();
            $table->foreign('customer_id', 'cleaning_session_penalty_customer_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('financial_transaction_id', 'cleaning_session_penalty_transaction_fk')
                ->references('id')
                ->on('cleaning_deposit_transactions')
                ->nullOnDelete();
        });
    }

    private function ensureIndexes(): void
    {
        $indexes = [
            'cleaning_session_penalty_reference_unique' => [
                'columns' => ['reference_key'],
                'unique' => true,
            ],
            'cleaning_session_penalty_session_role_idx' => [
                'columns' => ['cleaning_booking_session_id', 'penalized_role', 'status'],
                'unique' => false,
            ],
            'cleaning_session_penalty_booking_status_idx' => [
                'columns' => ['cleaning_booking_id', 'status'],
                'unique' => false,
            ],
            'cleaning_session_penalty_worker_status_idx' => [
                'columns' => ['worker_id', 'status'],
                'unique' => false,
            ],
            'cleaning_session_penalty_customer_status_idx' => [
                'columns' => ['customer_id', 'status'],
                'unique' => false,
            ],
        ];

        foreach ($indexes as $name => $definition) {
            if (Schema::hasIndex(self::TABLE, $name)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);

                    return;
                }

                $table->index($definition['columns'], $name);
            });
        }
    }

    private function ensureForeignKeys(): void
    {
        $foreignKeys = [
            'cleaning_booking_id' => [
                'name' => 'cleaning_session_penalty_booking_fk',
                'table' => 'cleaning_bookings',
                'onDelete' => 'cascade',
            ],
            'cleaning_booking_session_id' => [
                'name' => 'cleaning_session_penalty_session_fk',
                'table' => 'cleaning_booking_sessions',
                'onDelete' => 'cascade',
            ],
            'worker_id' => [
                'name' => 'cleaning_session_penalty_worker_fk',
                'table' => 'workers',
                'onDelete' => 'set null',
            ],
            'customer_id' => [
                'name' => 'cleaning_session_penalty_customer_fk',
                'table' => 'users',
                'onDelete' => 'set null',
            ],
            'financial_transaction_id' => [
                'name' => 'cleaning_session_penalty_transaction_fk',
                'table' => 'cleaning_deposit_transactions',
                'onDelete' => 'set null',
            ],
        ];

        foreach ($foreignKeys as $column => $definition) {
            if ($this->hasForeignKey($column)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($column, $definition): void {
                $foreign = $table->foreign($column, $definition['name'])
                    ->references('id')
                    ->on($definition['table']);

                if ($definition['onDelete'] === 'cascade') {
                    $foreign->cascadeOnDelete();

                    return;
                }

                $foreign->nullOnDelete();
            });
        }
    }

    private function hasForeignKey(string $column): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
