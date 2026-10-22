<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Services\CleaningTransactionAdmissionService as Admission;

const CLEANING_ADMISSION_TEST_CONNECTION = 'cleaning_admission_isolated_test';

beforeEach(function (): void {
    $this->admissionOriginalDefault = DB::getDefaultConnection();
    $this->admissionOriginalConfig = config('database.connections.'.CLEANING_ADMISSION_TEST_CONNECTION);

    config([
        'database.connections.'.CLEANING_ADMISSION_TEST_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(CLEANING_ADMISSION_TEST_CONNECTION);
    DB::setDefaultConnection(CLEANING_ADMISSION_TEST_CONNECTION);

    $migrationPath = base_path('Modules/Cleaning/database/migrations/2026_10_18_000000_create_cleaning_transaction_admission_locks.php');
    /** @var Migration $migration */
    $migration = require $migrationPath;
    $migration->up();

    DB::statement('CREATE TABLE admission_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');
});

afterEach(function (): void {
    DB::setDefaultConnection($this->admissionOriginalDefault);
    DB::disconnect(CLEANING_ADMISSION_TEST_CONNECTION);
    DB::purge(CLEANING_ADMISSION_TEST_CONNECTION);

    if ($this->admissionOriginalConfig === null) {
        config(['database.connections.'.CLEANING_ADMISSION_TEST_CONNECTION => null]);
    } else {
        config(['database.connections.'.CLEANING_ADMISSION_TEST_CONNECTION => $this->admissionOriginalConfig]);
    }
});

function cleaningAdmissionInsert(string $value): void
{
    DB::table('admission_probe')->insert(['value' => $value]);
}

function cleaningAdmissionValues(): array
{
    return DB::table('admission_probe')->orderBy('id')->pluck('value')->all();
}

function cleaningAdmissionExpectLogic(callable $callback, string $messageFragment): void
{
    try {
        $callback();
        test()->fail('Expected a LogicException.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toContain($messageFragment);
    }
}

it('starts an owned root and makes the sentinel read its first SQL', function (): void {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if ($query->connectionName === CLEANING_ADMISSION_TEST_CONNECTION) {
            $queries[] = mb_strtolower($query->sql);
        }
    });

    $result = Admission::run(function ($context): string {
        expect($context)->toBeInstanceOf(Admission::class);
        cleaningAdmissionInsert('committed');

        return 'done';
    });

    expect($result)->toBe('done')
        ->and(cleaningAdmissionValues())->toBe(['committed'])
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
        ->toHaveCount(2)
        ->and($queries[0])->toContain('cleaning_transaction_admission_locks')
        ->and($queries[0])->toContain('where "id" = ?');
});

it('rolls back a failed root and permits a later independent root', function (): void {
    try {
        Admission::run(function (): void {
            cleaningAdmissionInsert('rolled-back');
            throw new RuntimeException('root failed');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('root failed');
    }

    Admission::run(fn () => cleaningAdmissionInsert('next-root'));

    expect(cleaningAdmissionValues())->toBe(['next-root'])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('uses a savepoint for an explicit nested context and rolls back only nested writes', function (): void {
    Admission::run(function ($context): void {
        cleaningAdmissionInsert('outer-before');

        try {
            Admission::run(function (): void {
                cleaningAdmissionInsert('nested-rollback');
                throw new RuntimeException('nested failed');
            }, $context);
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('nested failed');
        }

        Admission::run(function (): void {
            cleaningAdmissionInsert('nested-commit');
        }, $context);
        cleaningAdmissionInsert('outer-after');
    });

    expect(cleaningAdmissionValues())->toBe(['outer-before', 'nested-commit', 'outer-after'])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('rejects a nested call without context while preserving the admitted root', function (): void {
    Admission::run(function (): void {
        cleaningAdmissionExpectLogic(
            fn () => Admission::run(fn () => cleaningAdmissionInsert('forbidden')),
            'unowned root transaction',
        );
        cleaningAdmissionInsert('permitted');
    });

    expect(cleaningAdmissionValues())->toBe(['permitted']);
});

it('rejects inherited managed transactions before SQL or callback and leaves ownership alone', function (): void {
    $connection = DB::connection();
    $connection->beginTransaction();
    DB::select('SELECT value FROM admission_probe');
    $depth = $connection->transactionLevel();
    $queries = [];
    $called = false;
    DB::listen(function ($query) use (&$queries): void {
        if ($query->connectionName === CLEANING_ADMISSION_TEST_CONNECTION) {
            $queries[] = $query->sql;
        }
    });

    cleaningAdmissionExpectLogic(function () use (&$called): void {
        Admission::run(function () use (&$called): void {
            $called = true;
        });
    }, 'unowned root transaction');

    expect($called)->toBeFalse()
        ->and($queries)->toBe([])
        ->and($connection->transactionLevel())->toBe($depth);

    $connection->rollBack();
});

it('rejects inherited raw PDO transactions at managed depth zero without taking ownership', function (): void {
    $connection = DB::connection();
    $pdo = $connection->getPdo();
    $pdo->beginTransaction();
    $called = false;
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if ($query->connectionName === CLEANING_ADMISSION_TEST_CONNECTION) {
            $queries[] = $query->sql;
        }
    });

    cleaningAdmissionExpectLogic(function () use (&$called): void {
        Admission::run(function () use (&$called): void {
            $called = true;
        });
    }, 'existing PDO transaction');

    expect($called)->toBeFalse()
        ->and($queries)->toBe([])
        ->and($connection->transactionLevel())->toBe(0)
        ->and($pdo->inTransaction())->toBeTrue();

    $pdo->rollBack();
});

it('rejects a context after its root exits and prevents cloning and serialization', function (): void {
    $expired = null;
    Admission::run(function ($context) use (&$expired): void {
        $expired = $context;

        try {
            clone $context;
            test()->fail('Cloning should fail.');
        } catch (LogicException $exception) {
            expect($exception->getMessage())->toContain('cannot be cloned');
        }

        try {
            serialize($context);
            test()->fail('Serialization should fail.');
        } catch (LogicException $exception) {
            expect($exception->getMessage())->toContain('cannot be serialized');
        }

        $class = Admission::class;
        $serializedObject = sprintf('O:%d:"%s":0:{}', mb_strlen($class), $class);
        try {
            unserialize($serializedObject);
            test()->fail('Unserialization should fail.');
        } catch (LogicException $exception) {
            expect($exception->getMessage())->toContain('cannot be unserialized');
        }
    });

    cleaningAdmissionExpectLogic(fn () => Admission::run(fn () => null, $expired), 'expired');
});

it('rejects a changed default connection without reconnecting or losing the owned root', function (): void {
    Admission::run(function ($context): void {
        DB::setDefaultConnection($this->admissionOriginalDefault);
        try {
            cleaningAdmissionExpectLogic(fn () => Admission::run(fn () => null, $context), 'default connection changed');
        } finally {
            DB::setDefaultConnection(CLEANING_ADMISSION_TEST_CONNECTION);
        }

        cleaningAdmissionInsert('still-owned');
    });

    expect(cleaningAdmissionValues())->toBe(['still-owned']);
});

it('rolls back its original root when a callback leaves the default changed', function (bool $throws): void {
    $owned = DB::connection(CLEANING_ADMISSION_TEST_CONNECTION);
    $originalPdo = $owned->getRawPdo();
    $expired = null;

    try {
        Admission::run(function ($context) use ($throws, &$expired): void {
            $expired = $context;
            cleaningAdmissionInsert('should-rollback');
            DB::setDefaultConnection($this->admissionOriginalDefault);

            if ($throws) {
                throw new RuntimeException('callback rejected');
            }
        });
        test()->fail('Changing the default connection must reject root completion.');
    } catch (LogicException|RuntimeException $exception) {
        expect($exception->getMessage())->toBe($throws ? 'callback rejected' : 'Cleaning admission default connection changed.');
    } finally {
        expect(DB::getDefaultConnection())->toBe($this->admissionOriginalDefault);
        DB::setDefaultConnection(CLEANING_ADMISSION_TEST_CONNECTION);
    }

    expect($owned->transactionLevel())->toBe(0)
        ->and($originalPdo->inTransaction())->toBeFalse()
        ->and(cleaningAdmissionValues())->toBe([]);
    cleaningAdmissionExpectLogic(fn () => Admission::run(fn () => null, $expired), 'expired');

    Admission::run(fn () => cleaningAdmissionInsert('next-independent-root'));
    expect(cleaningAdmissionValues())->toBe(['next-independent-root']);
})->with([false, true]);

it('unwinds an unbalanced callback savepoint instead of retaining the admission lock', function (): void {
    $owned = DB::connection(CLEANING_ADMISSION_TEST_CONNECTION);
    $pdo = $owned->getRawPdo();

    expect(fn () => Admission::run(function () use ($owned): void {
        cleaningAdmissionInsert('root-write');
        $owned->beginTransaction();
        cleaningAdmissionInsert('unbalanced-savepoint-write');
    }))->toThrow(LogicException::class, 'root transaction ownership changed');

    expect($owned->transactionLevel())->toBe(0)
        ->and($pdo->inTransaction())->toBeFalse()
        ->and(cleaningAdmissionValues())->toBe([]);
    Admission::run(fn () => cleaningAdmissionInsert('recovered'));
    expect(cleaningAdmissionValues())->toBe(['recovered']);
});

it('unwinds the root after a nested default-connection violation expires the token', function (): void {
    $owned = DB::connection(CLEANING_ADMISSION_TEST_CONNECTION);
    $pdo = $owned->getRawPdo();

    expect(fn () => Admission::run(function ($context): void {
        cleaningAdmissionInsert('root-write');
        try {
            Admission::run(function (): void {
                cleaningAdmissionInsert('nested-write');
                DB::setDefaultConnection($this->admissionOriginalDefault);
            }, $context);
        } catch (LogicException $exception) {
            expect($exception->getMessage())->toContain('default connection changed');
        } finally {
            DB::setDefaultConnection(CLEANING_ADMISSION_TEST_CONNECTION);
        }
        cleaningAdmissionInsert('after-nested-violation');
    }))->toThrow(LogicException::class, 'context has expired');

    expect($owned->transactionLevel())->toBe(0)
        ->and($pdo->inTransaction())->toBeFalse()
        ->and(cleaningAdmissionValues())->toBe([]);
    Admission::run(fn () => cleaningAdmissionInsert('healthy-root'));
    expect(cleaningAdmissionValues())->toBe(['healthy-root']);
});

it('rejects a disconnected context without reconnecting', function (): void {
    expect(fn () => Admission::run(function ($context): void {
        cleaningAdmissionInsert('owned-before-disconnect');
        DB::disconnect(CLEANING_ADMISSION_TEST_CONNECTION);
        Admission::run(fn () => null, $context);
    }))->toThrow(LogicException::class, 'PDO instance changed');

    expect(DB::getConnections()[CLEANING_ADMISSION_TEST_CONNECTION]->getRawPdo())->toBeNull();
});

it('rejects an actual reconnect and preserves the replacement PDO without replay', function (): void {
    $owned = DB::connection(CLEANING_ADMISSION_TEST_CONNECTION);
    $originalPdo = $owned->getRawPdo();
    $replacementPdo = null;
    $nestedCalled = false;

    try {
        expect(function () use ($owned, $originalPdo, &$replacementPdo, &$nestedCalled): void {
            Admission::run(function ($context) use ($owned, $originalPdo, &$replacementPdo, &$nestedCalled): void {
                cleaningAdmissionInsert('uncommitted-before-reconnect');
                DB::disconnect(CLEANING_ADMISSION_TEST_CONNECTION);
                DB::reconnect(CLEANING_ADMISSION_TEST_CONNECTION);
                // Laravel may keep a lazy PDO resolver after reconnect; resolve it
                // in this test so the replacement really is a distinct live PDO.
                $replacementPdo = $owned->getPdo();

                expect($replacementPdo)->toBeInstanceOf(PDO::class)
                    ->not->toBe($originalPdo);

                Admission::run(function () use (&$nestedCalled): void {
                    $nestedCalled = true;
                }, $context);
            });
        })->toThrow(LogicException::class, 'PDO instance changed');

        expect($nestedCalled)->toBeFalse()
            ->and($owned->getRawPdo())->toBe($replacementPdo)
            ->and($replacementPdo->inTransaction())->toBeFalse();
    } finally {
        // Reconnecting is forbidden inside an admitted command. This fixture
        // deliberately restores and releases only its own isolated PDO root.
        $owned->setPdo($originalPdo);
        if ($owned->transactionLevel() > 0 && $originalPdo->inTransaction()) {
            $owned->rollBack(0);
        }
        if ($originalPdo->inTransaction()) {
            // Reconnect may reset Laravel's managed depth to zero while the
            // detached original PDO still owns its isolated fixture root.
            $originalPdo->rollBack();
        }
    }

    expect($owned->transactionLevel())->toBe(0)
        ->and($originalPdo->inTransaction())->toBeFalse()
        ->and(cleaningAdmissionValues())->toBe([]);
});

it('rejects a replaced managed root and leaves the replacement root untouched', function (): void {
    $replacementDepth = null;
    try {
        Admission::run(function ($context) use (&$replacementDepth): void {
            $connection = DB::connection();
            $connection->rollBack();
            $connection->beginTransaction();
            $replacementDepth = $connection->transactionLevel();

            cleaningAdmissionExpectLogic(fn () => Admission::run(fn () => null, $context), 'root transaction ownership changed');
            expect($connection->transactionLevel())->toBe(1)
                ->and($connection->getPdo()->inTransaction())->toBeTrue();
        });
        test()->fail('Replacing the admitted root must fail the outer operation.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toContain('root transaction ownership changed');
    }

    expect($replacementDepth)->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->rollBack();
});

it('rejects a lost managed root without trying to roll back another transaction', function (): void {
    $owned = DB::connection(CLEANING_ADMISSION_TEST_CONNECTION);

    expect(fn () => Admission::run(function ($context) use ($owned): void {
        cleaningAdmissionInsert('lost-root');
        $owned->rollBack();
        Admission::run(fn () => cleaningAdmissionInsert('must-not-run'), $context);
    }))->toThrow(LogicException::class, 'root transaction ownership changed');

    expect($owned->transactionLevel())->toBe(0)
        ->and($owned->getRawPdo()->inTransaction())->toBeFalse()
        ->and(cleaningAdmissionValues())->toBe([]);
    Admission::run(fn () => cleaningAdmissionInsert('new-root'));
    expect(cleaningAdmissionValues())->toBe(['new-root']);
});

it('fails closed for a missing table and missing sentinel without invoking the callback', function (): void {
    $called = false;
    DB::statement('DROP TABLE cleaning_transaction_admission_locks');

    expect(fn () => Admission::run(function () use (&$called): void {
        $called = true;
    }))->toThrow(QueryException::class, 'no such table');

    expect($called)->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasTable('cleaning_transaction_admission_locks'))->toBeFalse();

    $migrationPath = base_path('Modules/Cleaning/database/migrations/2026_10_18_000000_create_cleaning_transaction_admission_locks.php');
    /** @var Migration $migration */
    $migration = require $migrationPath;
    $migration->up();
    DB::table('cleaning_transaction_admission_locks')->delete();
    $called = false;

    expect(fn () => Admission::run(function () use (&$called): void {
        $called = true;
    }))->toThrow(RuntimeException::class, 'sentinel is missing');

    expect($called)->toBeFalse()
        ->and(DB::table('cleaning_transaction_admission_locks')->count())->toBe(0);
});

it('enforces the singleton check and primary key on the actual migrated table', function (): void {
    expect(DB::table('cleaning_transaction_admission_locks')->pluck('id')->all())->toBe([1]);

    expect(fn () => DB::table('cleaning_transaction_admission_locks')->insert(['id' => 2]))
        ->toThrow(QueryException::class, 'CHECK constraint failed');
    expect(fn () => DB::table('cleaning_transaction_admission_locks')->insert(['id' => 1]))
        ->toThrow(QueryException::class, 'UNIQUE constraint failed');

    expect(DB::table('cleaning_transaction_admission_locks')->pluck('id')->all())->toBe([1]);
});
