<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class CleaningTransactionAdmissionService
{
    private const TABLE = 'cleaning_transaction_admission_locks';

    private function __construct(
        private readonly string $connectionName,
        private readonly Connection $connection,
        private readonly PDO $pdo,
        private readonly DatabaseTransactionRecord $rootRecord,
        private int $expectedDepth,
        private bool $active = true,
    ) {}

    public function __clone(): void
    {
        throw new LogicException('Cleaning admission context cannot be cloned.');
    }

    public function __serialize(): array
    {
        throw new LogicException('Cleaning admission context cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Cleaning admission context cannot be unserialized.');
    }

    public static function run(Closure $command, ?self $context = null): mixed
    {
        return $context === null
            ? self::runRoot($command)
            : self::runNested($command, $context);
    }

    private static function runRoot(Closure $command): mixed
    {
        $connectionName = DB::getDefaultConnection();
        $connection = DB::connection($connectionName);

        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Cleaning admission requires an unowned root transaction.');
        }

        $rawPdo = $connection->getRawPdo();
        if ($rawPdo instanceof PDO && $rawPdo->inTransaction()) {
            throw new LogicException('Cleaning admission cannot adopt an existing PDO transaction.');
        }

        $connection->beginTransaction();
        $pdo = $connection->getRawPdo();
        if (! $pdo instanceof PDO) {
            self::rollbackOwnedRootIfIntact($connectionName, $connection, null, null);
            throw new RuntimeException('Cleaning admission could not establish its root PDO.');
        }

        $rootRecord = self::findRootRecord($connectionName, $connection);
        if ($rootRecord === null) {
            self::rollbackOwnedRootIfIntact($connectionName, $connection, $pdo, null);
            throw new RuntimeException('Cleaning admission could not identify its root transaction.');
        }

        $context = new self($connectionName, $connection, $pdo, $rootRecord, 1);

        try {
            self::assertContext($context, 1);
            $sentinel = $connection->table(self::TABLE)
                ->where('id', 1)
                ->lockForUpdate()
                ->first(['id']);

            if ($sentinel === null) {
                throw new RuntimeException('Cleaning admission sentinel is missing.');
            }

            self::assertContext($context, 1);
            $result = $command($context);
            self::assertContext($context, 1);
            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            // Callback validation (the active token, default connection and exact
            // nesting depth) is stricter than the proof needed to clean up the
            // original transaction. A rejected callback may have changed the
            // default connection or left a savepoint open. Do not strand the
            // owned transaction and its admission lock in either case.
            if (self::ownsOriginalRoot($context)) {
                try {
                    $connection->rollBack(0);
                } catch (Throwable) {
                    // Preserve the original failure; never touch an unproven replacement root.
                }
            }

            throw $exception;
        } finally {
            $context->active = false;
        }
    }

    private static function runNested(Closure $command, self $context): mixed
    {
        self::assertContext($context, $context->expectedDepth);
        $parentDepth = $context->expectedDepth;
        $nestedDepth = $parentDepth + 1;
        $context->connection->beginTransaction();
        $context->expectedDepth = $nestedDepth;

        try {
            self::assertContext($context, $nestedDepth);
            $result = $command($context);
            self::assertContext($context, $nestedDepth);
            $context->connection->commit();
            $context->expectedDepth = $parentDepth;

            return $result;
        } catch (Throwable $exception) {
            if (self::contextOwnsCurrentRoot($context, $nestedDepth)) {
                try {
                    $context->connection->rollBack($parentDepth);
                } catch (Throwable) {
                    // Preserve the original failure. Ownership was checked immediately above.
                }
            } else {
                $context->active = false;
            }

            $context->expectedDepth = $parentDepth;

            throw $exception;
        }
    }

    private static function assertContext(self $context, int $expectedDepth): void
    {
        if (! $context->active) {
            throw new LogicException('Cleaning admission context has expired.');
        }

        if (DB::getDefaultConnection() !== $context->connectionName) {
            throw new LogicException('Cleaning admission default connection changed.');
        }

        if ((DB::getConnections()[$context->connectionName] ?? null) !== $context->connection) {
            throw new LogicException('Cleaning admission connection instance changed.');
        }

        if ($context->connection->getRawPdo() !== $context->pdo) {
            throw new LogicException('Cleaning admission PDO instance changed.');
        }

        if ($context->connection->transactionLevel() !== $expectedDepth ||
            ! $context->pdo->inTransaction() ||
            self::findRootRecord($context->connectionName, $context->connection) !== $context->rootRecord) {
            throw new LogicException('Cleaning admission root transaction ownership changed.');
        }
    }

    private static function contextOwnsCurrentRoot(self $context, int $expectedDepth): bool
    {
        return $context->active &&
            DB::getDefaultConnection() === $context->connectionName &&
            (DB::getConnections()[$context->connectionName] ?? null) === $context->connection &&
            $context->connection->getRawPdo() === $context->pdo &&
            $context->connection->transactionLevel() === $expectedDepth &&
            $context->pdo->inTransaction() &&
            self::findRootRecord($context->connectionName, $context->connection) === $context->rootRecord;
    }

    private static function ownsOriginalRoot(self $context): bool
    {
        return (DB::getConnections()[$context->connectionName] ?? null) === $context->connection &&
            $context->connection->getRawPdo() === $context->pdo &&
            $context->connection->transactionLevel() >= 1 &&
            $context->pdo->inTransaction() &&
            self::findRootRecord($context->connectionName, $context->connection) === $context->rootRecord;
    }

    private static function rollbackOwnedRootIfIntact(
        string $connectionName,
        Connection $connection,
        ?PDO $pdo,
        ?DatabaseTransactionRecord $rootRecord,
    ): void {
        if ($connection->transactionLevel() === 1 &&
            ($pdo === null || $connection->getRawPdo() === $pdo) &&
            ($rootRecord === null || self::findRootRecord($connectionName, $connection) === $rootRecord) &&
            ($pdo === null || $pdo->inTransaction())) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // Do not replace the root-identification failure with cleanup noise.
            }
        }
    }

    private static function findRootRecord(string $connectionName, Connection $connection): ?DatabaseTransactionRecord
    {
        $matches = app('db.transactions')
            ->getPendingTransactions()
            ->filter(fn (DatabaseTransactionRecord $record): bool => $record->connection === $connectionName &&
                $record->level === 1 &&
                $record->parent === null
            );

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
