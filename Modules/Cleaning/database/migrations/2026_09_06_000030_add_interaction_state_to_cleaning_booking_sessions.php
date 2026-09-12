<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addSessionPaymentState();
        $this->addSessionScopedRatings();
        $this->addSessionScopedDisputes();
    }

    private function addSessionPaymentState(): void
    {
        $missingPaymentStatus = ! Schema::hasColumn('cleaning_booking_sessions', 'payment_status');
        $missingPaymentSettledAt = ! Schema::hasColumn('cleaning_booking_sessions', 'payment_settled_at');

        if ($missingPaymentStatus || $missingPaymentSettledAt) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table) use (
                $missingPaymentStatus,
                $missingPaymentSettledAt,
            ): void {
                if ($missingPaymentStatus) {
                    $table->string('payment_status')->default('pending')->after('is_pricing_final');
                }
                if ($missingPaymentSettledAt) {
                    $table->timestamp('payment_settled_at')->nullable()->after('payment_status');
                }
            });
        }

        if (! Schema::hasIndex('cleaning_booking_sessions', 'cleaning_session_payment_status_idx')) {
            Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                $table->index(['payment_status', 'scheduled_date'], 'cleaning_session_payment_status_idx');
            });
        }
    }

    private function addSessionScopedRatings(): void
    {
        if (! Schema::hasColumn('worker_customer_ratings', 'cleaning_booking_session_id')) {
            Schema::table('worker_customer_ratings', function (Blueprint $table): void {
                $table->foreignId('cleaning_booking_session_id')
                    ->nullable()
                    ->after('booking_type')
                    ->constrained('cleaning_booking_sessions')
                    ->nullOnDelete();
            });
        } elseif (! $this->hasForeignKeyForColumn('worker_customer_ratings', 'cleaning_booking_session_id')) {
            Schema::table('worker_customer_ratings', function (Blueprint $table): void {
                $table->foreign('cleaning_booking_session_id')
                    ->references('id')
                    ->on('cleaning_booking_sessions')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasIndex('worker_customer_ratings', 'wcr_booking_worker_customer_rating_unique')) {
            Schema::table('worker_customer_ratings', function (Blueprint $table): void {
                $table->dropUnique('wcr_booking_worker_customer_rating_unique');
            });
        }

        if (! Schema::hasIndex('worker_customer_ratings', 'wcr_booking_session_worker_rating_unique')) {
            Schema::table('worker_customer_ratings', function (Blueprint $table): void {
                $table->unique(
                    [
                        'booking_id',
                        'booking_type',
                        'cleaning_booking_session_id',
                        'worker_id',
                        'customer_id',
                        'rating_type',
                    ],
                    'wcr_booking_session_worker_rating_unique',
                );
            });
        }
    }

    private function addSessionScopedDisputes(): void
    {
        // The merged dev history may already have this column from
        // 2026_08_26_190520_add_cleaning_booking_session_scope_to_support_tables.
        if (! Schema::hasColumn('disputes', 'cleaning_booking_session_id')) {
            Schema::table('disputes', function (Blueprint $table): void {
                $table->foreignId('cleaning_booking_session_id')
                    ->nullable()
                    ->after('booking_type')
                    ->constrained('cleaning_booking_sessions')
                    ->nullOnDelete();
            });
        } elseif (! $this->hasForeignKeyForColumn('disputes', 'cleaning_booking_session_id')) {
            Schema::table('disputes', function (Blueprint $table): void {
                $table->foreign('cleaning_booking_session_id')
                    ->references('id')
                    ->on('cleaning_booking_sessions')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasIndex('disputes', 'disputes_cleaning_session_status_idx')) {
            Schema::table('disputes', function (Blueprint $table): void {
                $table->index(
                    ['cleaning_booking_session_id', 'status'],
                    'disputes_cleaning_session_status_idx',
                );
            });
        }
    }

    private function hasForeignKeyForColumn(string $tableName, string $column): bool
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        if (Schema::hasTable('disputes')) {
            if (Schema::hasIndex('disputes', 'disputes_cleaning_session_status_idx')) {
                Schema::table('disputes', function (Blueprint $table): void {
                    $table->dropIndex('disputes_cleaning_session_status_idx');
                });
            }

            if ($this->hasForeignKeyForColumn('disputes', 'cleaning_booking_session_id')) {
                Schema::table('disputes', function (Blueprint $table): void {
                    $table->dropForeign(['cleaning_booking_session_id']);
                });
            }

            // Do not drop cleaning_booking_session_id here: the earlier merged
            // August migration owns that compatibility column.
        }

        if (Schema::hasTable('worker_customer_ratings')) {
            if (Schema::hasIndex('worker_customer_ratings', 'wcr_booking_session_worker_rating_unique')) {
                Schema::table('worker_customer_ratings', function (Blueprint $table): void {
                    $table->dropUnique('wcr_booking_session_worker_rating_unique');
                });
            }

            if ($this->hasForeignKeyForColumn('worker_customer_ratings', 'cleaning_booking_session_id')) {
                Schema::table('worker_customer_ratings', function (Blueprint $table): void {
                    $table->dropForeign(['cleaning_booking_session_id']);
                });
            }

            if (Schema::hasColumn('worker_customer_ratings', 'cleaning_booking_session_id')) {
                Schema::table('worker_customer_ratings', function (Blueprint $table): void {
                    $table->dropColumn('cleaning_booking_session_id');
                });
            }

            if (! Schema::hasIndex('worker_customer_ratings', 'wcr_booking_worker_customer_rating_unique')) {
                Schema::table('worker_customer_ratings', function (Blueprint $table): void {
                    $table->unique(
                        ['booking_id', 'booking_type', 'worker_id', 'customer_id', 'rating_type'],
                        'wcr_booking_worker_customer_rating_unique',
                    );
                });
            }
        }

        if (Schema::hasTable('cleaning_booking_sessions')) {
            if (Schema::hasIndex('cleaning_booking_sessions', 'cleaning_session_payment_status_idx')) {
                Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
                    $table->dropIndex('cleaning_session_payment_status_idx');
                });
            }

            $columns = array_values(array_filter([
                'payment_status',
                'payment_settled_at',
            ], static fn (string $column): bool => Schema::hasColumn('cleaning_booking_sessions', $column)));

            if ($columns !== []) {
                Schema::table('cleaning_booking_sessions', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
