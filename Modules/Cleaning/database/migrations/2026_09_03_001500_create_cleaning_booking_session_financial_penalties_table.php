<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'cleaning_booking_session_financial_penalties';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
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

            return;
        }

        // MySQL may persist CREATE TABLE even when a following ALTER TABLE
        // fails. Complete that partially-created table without dropping it.
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
            if (Schema::hasIndex($tableName, $name)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);

                    return;
                }

                $table->index($definition['columns'], $name);
            });
        }

        $existingForeignKeyColumns = collect(Schema::getForeignKeys($tableName))
            ->flatMap(static fn (array $foreignKey): array => $foreignKey['columns'] ?? [])
            ->all();

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
            if (in_array($column, $existingForeignKeyColumns, true)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($column, $definition): void {
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

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_session_financial_penalties');
    }
};
